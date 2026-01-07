<?php

namespace App\Controller\Front;

use App\Entity\Note;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\NoteAssignment;
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
use App\Controller\Front\NoteController;


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

        // Teams list
        $myTeams = $teams->createQueryBuilder('t')
            ->leftJoin('t.members', 'm')
            ->where('t.owner = :u OR m.user = :u')
            ->setParameter('u', $user)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        // Active team (null = personal)
        $team = null;
        $teamUsers = [];

        if ($teamId) {
            $team = $teams->find($teamId);

            if (!$team || !$this->canAccessTeam($team, $user)) {
                throw $this->createAccessDeniedException('You do not have access to this team.');
            }

            $teamUsers = $this->getTeamUsers($team);
        }

        // Who can create notes?
        // - personal workspace: yes
        // - team workspace: only team owner (adjust if you want members too)
        $canCreateNote = ($team === null) || ($team->getOwner()?->getId() === $user->getId());

        // Create form ONLY if canCreateNote
        $note = new Note();
        $note->setCreatedBy($user);
        if ($team) {
            $note->setTeam($team);
        }

        $form = $this->createForm(NoteType::class, $note);
        $form->handleRequest($request);

        if ($canCreateNote && $form->isSubmitted() && $form->isValid()) {
            $note->touch(); // updatedAt

            $em->persist($note);
            $em->flush();

            if ($team) {
                $url = $this->generateUrl('app_note', ['teamId' => $team->getId()]);
                $notifier->notifyTeam($team, $user, 'New note created', $note->getTitle(), $url, $note);
            }

            $this->addFlash('success', 'Note created successfully ✨');
            return $this->redirectToRoute('app_note', $teamId ? ['teamId' => $teamId] : []);
        }

        // Fetch notes
        $allNotes = $team
            ? $notes->findByTeam($team)
            : $notes->findBy(['createdBy' => $user, 'team' => null], ['createdAt' => 'DESC']);

        // Filter: show only notes where user is owner or assigned
        $visibleNotes = [];
        foreach ($allNotes as $n) {
            $isOwner = $n->getCreatedBy()?->getId() === $user->getId();
            $isAssignee = $n->isAssignedTo($user);
            if ($isOwner || $isAssignee) {
                $visibleNotes[] = $n;
            }
        }

        // Group by status
        $grouped = ['todo' => [], 'in_progress' => [], 'done' => []];
        foreach ($visibleNotes as $n) {
            $grouped[$n->getStatus()->value][] = $n;
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

    if (!$this->isCsrfTokenValid('status_note_' . $note->getId(), (string) $request->request->get('_token'))) {
        $this->addFlash('danger', 'ERROR: Le jeton CSRF est invalide. Veuillez renvoyer le formulaire.');
        return $this->redirectBackToBoard($note);
    }

    $isOwner = $note->getCreatedBy()?->getId() === $user->getId();
    $isAssignee = $note->isAssignedTo($user);

    // Only owner OR assigned can change status
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

    // si aucun changement, pas besoin de notifier
    if ($oldStatus === $newStatus) {
        return $this->redirectBackToBoard($note);
    }

    $note->setStatus($newStatus);
    $note->touch();

    $em->flush();

    // ✅ Notifications (team only)
    $team = $note->getTeam();
    if ($team) {
        $url = $this->generateUrl('app_note', ['teamId' => $team->getId()]);

        // Message selon statut
        $title = $newStatus === NoteStatus::DONE
            ? 'Task completed ✅'
            : 'Task progress updated';

        // Petit texte lisible
        $description = sprintf(
            '%s changed "%s" from %s → %s',
            $user->getEmail(),
            $note->getTitle(),
            $oldStatus->label(),
            $newStatus->label()
        );

        // Notifie l’équipe (selon ton service)
        $notifier->notifyTeam(
            $team,
            $user,
            $title,
            $description,
            $url,
            $note
        );
    }

    $this->addFlash('success', 'Status updated ✅');
    return $this->redirectBackToBoard($note);
}




#[Route('/app/notes/{id}/assign', name: 'app_note_assign', requirements: ['id' => '\d+'], methods: ['POST'])]
public function assign(
    Note $note,
    Request $request,
    EntityManagerInterface $em,
    UserRepository $users
): Response {
    $currentUser = $this->getUser();
    \assert($currentUser instanceof User);

    if (!$this->isCsrfTokenValid('assign_note_' . $note->getId(), (string) $request->request->get('_token'))) {
        $this->addFlash('danger', 'ERROR: Le jeton CSRF est invalide.');
        return $this->redirectBackToBoard($note);
    }

    // ✅ sécurité : seul le créateur (ou owner team) peut assigner
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
    if (!$assignee) {
        $this->addFlash('warning', 'User not found.');
        return $this->redirectBackToBoard($note);
    }

    // ✅ éviter doublons
    if ($note->isAssignedTo($assignee)) {
        $this->addFlash('info', 'Already assigned.');
        return $this->redirectBackToBoard($note);
    }

    $assignment = (new NoteAssignment())
        ->setNote($note)
        ->setAssignee($assignee)
        ->setAssignedBy($currentUser) // ✅ C’EST ÇA QUI MANQUAIT
    ;

    $em->persist($assignment);
    $em->flush();

    $this->addFlash('success', 'Assigned ✅');
    return $this->redirectBackToBoard($note);
}

    #[Route('/app/note/{id}/unassign/{userId}', name: 'app_note_unassign', methods: ['POST'])]
    public function unassign(
        Note $note,
        int $userId,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $this->denyAccessUnlessGranted('NOTE_ASSIGN', $note);

        if (!$this->isCsrfTokenValid('unassign_note_'.$note->getId(), (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        foreach ($note->getAssignments() as $assignment) {
            if ($assignment->getAssignee()->getId() === $userId) {
                $note->removeAssignment($assignment);
                $em->remove($assignment);
                $note->touch();
                $em->flush();
                
                $this->addFlash('success', 'User unassigned successfully.');
                break;
            }
        }

        return $this->redirectToRoute('app_note', $this->getRedirectParams($note));
    }



    #[Route('/app/note/{id}/update', name: 'app_note_update', methods: ['POST'])]
    public function quickUpdate(
        Note $note,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $this->denyAccessUnlessGranted('NOTE_EDIT', $note);

        if (!$this->isCsrfTokenValid('update_note_'.$note->getId(), (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $field = $request->request->get('field');
        $value = $request->request->get('value');

        switch ($field) {
            case 'title':
                $note->setTitle($value);
                break;
            case 'dueAt':
                $note->setDueAt($value ? new \DateTimeImmutable($value) : null);
                break;
            case 'priority':
                // If you have a priority field
                // $note->setPriority($value);
                break;
        }

        $note->touch();
        $em->flush();

        return $this->json(['success' => true]);
    }
  #[Route('/app/notes/{id}/delete', name: 'app_note_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        Note $note,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        if (!$this->isCsrfTokenValid('delete_note_' . $note->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'ERROR: Le jeton CSRF est invalide. Veuillez renvoyer le formulaire.');
            return $this->redirectBackToBoard($note);
        }

        // Only owner can delete
        if ($note->getCreatedBy()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('You cannot delete this note.');
        }

        $teamId = $note->getTeam()?->getId();

        $em->remove($note);
        $em->flush();

        $this->addFlash('success', 'Note deleted 🗑️');

        return $this->redirectToRoute('app_note', $teamId ? ['teamId' => $teamId] : []);
    }

    private function redirectBackToBoard(Note $note): Response
    {
        $teamId = $note->getTeam()?->getId();
        return $this->redirectToRoute('app_note', $teamId ? ['teamId' => $teamId] : []);
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
            $u = $member->getUser();
            if ($u && !\in_array($u, $users, true)) {
                $users[] = $u;
            }
        }

        return $users;
    }

}