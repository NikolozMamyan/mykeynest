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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class NoteController extends AbstractController
{
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

            $this->addFlash('success', 'Note created successfully.');

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

        return $this->render('note/index.html.twig', [
            'team' => $team,
            'myTeams' => $myTeams,
            'teamUsers' => $teamUsers,
            'form' => $form,
            'notesByStatus' => $grouped,
            'isPersonalWorkspace' => $team === null,
            'canCreateNote' => $canCreateNote,
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
            $this->addFlash('danger', 'ERROR: Invalid CSRF token.');

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
            $this->addFlash('warning', 'Invalid status.');

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

        $this->addFlash('success', 'Status updated.');

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
            $this->addFlash('danger', 'ERROR: Invalid CSRF token.');

            return $this->redirectBackToBoard($note);
        }

        $isOwner = $note->getCreatedBy()?->getId() === $currentUser->getId();
        if (!$isOwner) {
            throw $this->createAccessDeniedException('You cannot assign users for this note.');
        }

        $assigneeId = $request->request->get('assignee_id');
        if (!$assigneeId) {
            $this->addFlash('warning', 'Please select a user.');

            return $this->redirectBackToBoard($note);
        }

        $assignee = $users->find((int) $assigneeId);
        if (!$assignee instanceof User) {
            $this->addFlash('warning', 'User not found.');

            return $this->redirectBackToBoard($note);
        }

        if ($note->isAssignedTo($assignee)) {
            $this->addFlash('info', 'Already assigned.');

            return $this->redirectBackToBoard($note);
        }

        $assignment = (new NoteAssignment())
            ->setNote($note)
            ->setAssignee($assignee)
            ->setAssignedBy($currentUser);

        $em->persist($assignment);
        $em->flush();

        $notifier->notifyNoteAssigned($note, $assignee, $currentUser);

        $this->addFlash('success', 'Assigned.');

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
            $this->addFlash('success', 'User unassigned successfully.');

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
            $this->addFlash('danger', 'ERROR: Invalid CSRF token.');

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

        $this->addFlash('success', 'Note deleted.');

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
        if ($user->hasActiveSubscription()) {
            return;
        }

        throw $this->createAccessDeniedException('Active subscription required.');
    }
}
