<?php

namespace App\Controller\Front;

use App\Entity\Note;
use App\Entity\NoteAssignment;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\NoteStatus;
use App\Form\NoteType;
use App\Repository\NoteRepository;
use App\Repository\TeamRepository;
use App\Repository\UserRepository;
use App\Service\NoteNotifier;
use App\Service\SubscriptionPlanService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class NoteController extends AbstractController
{
    public function __construct(
        private readonly SubscriptionPlanService $subscriptionPlans,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/app/notes/{teamId?}', name: 'app_note', requirements: ['teamId' => '\d+'], methods: ['GET', 'POST'])]
    public function index(
        ?int $teamId,
        Request $request,
        TeamRepository $teams,
        NoteRepository $notes,
        EntityManagerInterface $em,
        NoteNotifier $notifier
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);
        $this->denyUnlessSubscribed($user);

        $myTeams = $teams->createQueryBuilder('t')
            ->select('DISTINCT t')
            ->leftJoin('t.members', 'm')
            ->where('t.owner = :u OR m.user = :u')
            ->setParameter('u', $user)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $team = null;
        $teamUsers = [];

        if ($teamId) {
            $team = $teams->find($teamId);

            if (!$team || !$this->canAccessTeam($team, $user)) {
                throw $this->createAccessDeniedException('You do not have access to this team.');
            }

            $teamUsers = $this->getTeamUsers($team);
        }

        $canCreateNote = ($team === null) || ($team->getOwner()?->getId() === $user->getId());

        $note = new Note();
        $note->setCreatedBy($user);
        if ($team) {
            $note->setTeam($team);
        }

        $form = $this->createForm(NoteType::class, $note);
        $form->handleRequest($request);

        if ($canCreateNote && $form->isSubmitted() && $form->isValid()) {
            $note->touch();

            $em->persist($note);
            $em->flush();

            if ($team) {
                $notifier->notifyNoteCreated($note, $user);
            }

            $this->addFlash('success', $this->translator->trans('note.flash.created'));

            return $this->redirectToRoute('app_note', $teamId ? ['teamId' => $teamId] : []);
        }

        $allNotes = $team
            ? $notes->findByTeam($team)
            : $notes->findBy(['createdBy' => $user, 'team' => null], ['createdAt' => 'DESC']);

        $visibleNotes = [];
        foreach ($allNotes as $candidate) {
            $isOwner = $candidate->getCreatedBy()?->getId() === $user->getId();
            $isAssignee = $candidate->isAssignedTo($user);

            if ($isOwner || $isAssignee) {
                $visibleNotes[] = $candidate;
            }
        }

        $grouped = ['todo' => [], 'in_progress' => [], 'done' => []];
        foreach ($visibleNotes as $visibleNote) {
            $grouped[$visibleNote->getStatus()->value][] = $visibleNote;
        }

        $now = new \DateTimeImmutable();
        $nextWeek = $now->modify('+7 days');
        $stats = [
            'total' => count($visibleNotes),
            'done' => count($grouped[NoteStatus::DONE->value]),
            'overdue' => 0,
            'dueSoon' => 0,
            'assignedToMe' => 0,
        ];

        foreach ($visibleNotes as $visibleNote) {
            if ($visibleNote->isAssignedTo($user)) {
                ++$stats['assignedToMe'];
            }

            $dueAt = $visibleNote->getDueAt();
            if ($dueAt === null || $visibleNote->getStatus() === NoteStatus::DONE) {
                continue;
            }

            if ($dueAt < $now) {
                ++$stats['overdue'];
            } elseif ($dueAt <= $nextWeek) {
                ++$stats['dueSoon'];
            }
        }

        return $this->render('note/index.html.twig', [
            'team' => $team,
            'myTeams' => $myTeams,
            'teamUsers' => $teamUsers,
            'form' => $form,
            'notesByStatus' => $grouped,
            'isPersonalWorkspace' => $team === null,
            'canCreateNote' => $canCreateNote,
            'noteStats' => $stats,
            'now' => $now,
            'nextWeek' => $nextWeek,
        ]);
    }

    #[Route('/app/notes/{id}/status', name: 'app_note_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateStatus(
        Note $note,
        Request $request,
        EntityManagerInterface $em,
        NoteNotifier $notifier
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);
        $this->denyUnlessSubscribed($user);

        if (!$this->isCsrfTokenValid('status_note_' . $note->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('note.flash.invalid_request'));

            return $this->redirectBackToBoard($note);
        }

        $isOwner = $note->getCreatedBy()?->getId() === $user->getId();
        $isAssignee = $note->isAssignedTo($user);

        if (!$isOwner && !$isAssignee) {
            throw $this->createAccessDeniedException('You cannot change status for this note.');
        }

        $statusValue = (string) $request->request->get('status');
        $newStatus = NoteStatus::tryFrom($statusValue);

        if (!$newStatus) {
            $this->addFlash('error', $this->translator->trans('note.flash.invalid_status'));

            return $this->redirectBackToBoard($note);
        }

        $oldStatus = $note->getStatus();
        if ($oldStatus === $newStatus) {
            return $this->redirectBackToBoard($note);
        }

        $note->setStatus($newStatus);
        $note->touch();
        $em->flush();

        $notifier->notifyStatusChanged($note, $user, $oldStatus->value, $newStatus->value);

        $this->addFlash('success', $this->translator->trans('note.flash.status_updated'));

        return $this->redirectBackToBoard($note);
    }

    #[Route('/app/notes/{id}/assign', name: 'app_note_assign', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function assign(
        Note $note,
        Request $request,
        EntityManagerInterface $em,
        UserRepository $users,
        NoteNotifier $notifier
    ): Response {
        $currentUser = $this->getUser();
        \assert($currentUser instanceof User);
        $this->denyUnlessSubscribed($currentUser);

        if (!$this->isCsrfTokenValid('assign_note_' . $note->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('note.flash.invalid_request'));

            return $this->redirectBackToBoard($note);
        }

        $isOwner = $note->getCreatedBy()?->getId() === $currentUser->getId();
        if (!$isOwner) {
            throw $this->createAccessDeniedException('You cannot assign users for this note.');
        }

        $assigneeId = $request->request->get('assignee_id');
        if (!$assigneeId) {
            $this->addFlash('error', $this->translator->trans('note.flash.select_user'));

            return $this->redirectBackToBoard($note);
        }

        $assignee = $users->find((int) $assigneeId);
        if (!$assignee instanceof User) {
            $this->addFlash('error', $this->translator->trans('note.flash.user_not_found'));

            return $this->redirectBackToBoard($note);
        }

        if (!$this->canAssignUser($note, $assignee, $currentUser)) {
            $this->addFlash('error', $this->translator->trans('note.flash.invalid_assignee'));

            return $this->redirectBackToBoard($note);
        }

        if ($note->isAssignedTo($assignee)) {
            $this->addFlash('success', $this->translator->trans('note.flash.already_assigned'));

            return $this->redirectBackToBoard($note);
        }

        $assignment = (new NoteAssignment())
            ->setNote($note)
            ->setAssignee($assignee)
            ->setAssignedBy($currentUser);

        $em->persist($assignment);
        $em->flush();

        $notifier->notifyNoteAssigned($note, $assignee, $currentUser);

        $this->addFlash('success', $this->translator->trans('note.flash.assigned'));

        return $this->redirectBackToBoard($note);
    }

    #[Route('/app/note/{id}/unassign/{userId}', name: 'app_note_unassign', methods: ['POST'])]
    public function unassign(
        Note $note,
        int $userId,
        Request $request,
        EntityManagerInterface $em,
        NoteNotifier $notifier
    ): Response {
        $this->denyAccessUnlessGranted('NOTE_ASSIGN', $note);

        $user = $this->getUser();
        \assert($user instanceof User);
        $this->denyUnlessSubscribed($user);

        if (!$this->isCsrfTokenValid('unassign_note_' . $note->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        foreach ($note->getAssignments() as $assignment) {
            if ($assignment->getAssignee()->getId() !== $userId) {
                continue;
            }

            $assignee = $assignment->getAssignee();
            $note->removeAssignment($assignment);
            $em->remove($assignment);
            $note->touch();
            $em->flush();

            $notifier->notifyNoteUnassigned($note, $assignee, $user);
            $this->addFlash('success', $this->translator->trans('note.flash.unassigned'));

            break;
        }

        return $this->redirectToRoute('app_note', $this->getRedirectParams($note));
    }

    #[Route('/app/note/{id}/update', name: 'app_note_update', methods: ['POST'])]
    public function quickUpdate(
        Note $note,
        Request $request,
        EntityManagerInterface $em,
        NoteNotifier $notifier
    ): Response {
        $this->denyAccessUnlessGranted('NOTE_EDIT', $note);

        $user = $this->getUser();
        \assert($user instanceof User);
        $this->denyUnlessSubscribed($user);

        if (!$this->isCsrfTokenValid('update_note_' . $note->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $field = (string) $request->request->get('field');
        $value = $request->request->get('value');
        $hasChanged = false;
        $fieldLabel = '';
        $oldValue = null;
        $newValue = null;

        switch ($field) {
            case 'all':
                $title = trim((string) $request->request->get('title', ''));
                $content = trim((string) $request->request->get('content', ''));
                $dueAtValue = trim((string) $request->request->get('dueAt', ''));

                if ($title === '' || mb_strlen($title) > 255 || mb_strlen($content) > 50000) {
                    return $this->json(['success' => false, 'error' => $this->translator->trans('note.flash.invalid_content')], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                try {
                    $newDueAt = $dueAtValue !== '' ? new \DateTimeImmutable($dueAtValue) : null;
                } catch (\Exception) {
                    return $this->json(['success' => false, 'error' => $this->translator->trans('note.flash.invalid_due_date')], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $hasChanged = $note->getTitle() !== $title
                    || (string) $note->getContent() !== $content
                    || $note->getDueAt()?->format('Y-m-d H:i') !== $newDueAt?->format('Y-m-d H:i');

                if ($hasChanged) {
                    $note->setTitle($title);
                    $note->setContent($content !== '' ? $content : null);
                    $note->setDueAt($newDueAt);
                    $fieldLabel = 'details';
                }
                break;

            case 'title':
                $fieldLabel = 'title';
                $oldValue = $note->getTitle();
                $newValue = is_string($value) ? $value : null;

                if ($oldValue !== $newValue && $newValue !== null) {
                    $note->setTitle($newValue);
                    $hasChanged = true;
                }
                break;

            case 'dueAt':
                $fieldLabel = 'due date';
                $oldValue = $note->getDueAt()?->format('Y-m-d H:i');
                $newDueAt = is_string($value) && $value !== '' ? new \DateTimeImmutable($value) : null;
                $newValue = $newDueAt?->format('Y-m-d H:i');

                if ($oldValue !== $newValue) {
                    $note->setDueAt($newDueAt);
                    $hasChanged = true;
                }
                break;
        }

        if (!$hasChanged) {
            return $this->json(['success' => true]);
        }

        $note->touch();
        $em->flush();

        $notifier->notifyNoteUpdated($note, $user, $fieldLabel, $oldValue, $newValue);

        if ($field === 'all') {
            $this->addFlash('success', $this->translator->trans('note.flash.updated'));
        }

        return $this->json(['success' => true]);
    }

    #[Route('/app/notes/{id}/delete', name: 'app_note_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        Note $note,
        Request $request,
        EntityManagerInterface $em,
        NoteNotifier $notifier
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);
        $this->denyUnlessSubscribed($user);

        if (!$this->isCsrfTokenValid('delete_note_' . $note->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('note.flash.invalid_request'));

            return $this->redirectBackToBoard($note);
        }

        if ($note->getCreatedBy()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('You cannot delete this note.');
        }

        $team = $note->getTeam();
        $teamId = $team?->getId();
        $noteTitle = (string) $note->getTitle();

        $em->remove($note);
        $em->flush();

        if ($team) {
            $notifier->notifyNoteDeleted($team, $user, $noteTitle);
        }

        $this->addFlash('success', $this->translator->trans('note.flash.deleted'));

        return $this->redirectToRoute('app_note', $teamId ? ['teamId' => $teamId] : []);
    }

    private function redirectBackToBoard(Note $note): Response
    {
        return $this->redirectToRoute('app_note', $this->getRedirectParams($note));
    }

    private function getRedirectParams(Note $note): array
    {
        $teamId = $note->getTeam()?->getId();

        return $teamId ? ['teamId' => $teamId] : [];
    }

    private function canAccessTeam(Team $team, User $user): bool
    {
        if ($team->getOwner()?->getId() === $user->getId()) {
            return true;
        }

        foreach ($team->getMembers() as $member) {
            if ($member->getUser()?->getId() === $user->getId()) {
                return true;
            }
        }

        return false;
    }

    private function canAssignUser(Note $note, User $assignee, User $currentUser): bool
    {
        $team = $note->getTeam();
        if ($team === null) {
            return $assignee->getId() === $currentUser->getId();
        }

        foreach ($this->getTeamUsers($team) as $teamUser) {
            if ($teamUser->getId() === $assignee->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return User[]
     */
    private function getTeamUsers(Team $team): array
    {
        $users = [];

        if ($team->getOwner()) {
            $users[] = $team->getOwner();
        }

        foreach ($team->getMembers() as $member) {
            $candidate = $member->getUser();
            if ($candidate && !\in_array($candidate, $users, true)) {
                $users[] = $candidate;
            }
        }

        return $users;
    }

    private function denyUnlessSubscribed(User $user): void
    {
        if ($this->subscriptionPlans->hasFeature($user, SubscriptionPlanService::FEATURE_SECURE_NOTES)) {
            return;
        }

        throw $this->createAccessDeniedException('Les notes sécurisées ne sont pas disponibles avec votre plan.');
    }
}
