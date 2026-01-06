<?php

namespace App\Controller\Front;

use App\Entity\Note;
use App\Entity\NoteAssignment;
use App\Form\NoteType;
use App\Repository\NoteRepository;
use App\Repository\TeamRepository;
use App\Repository\UserRepository;
use App\Service\NoteNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class NoteController extends AbstractController
{
    #[Route('/app/note/{teamId?}', name: 'app_note', requirements: ['teamId' => '\d+'])]
    public function index(
        ?int $teamId,
        Request $request,
        TeamRepository $teams,
        NoteRepository $notes,
        EntityManagerInterface $em,
        NoteNotifier $notifier
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof \App\Entity\User);

        // Choix de team : si pas d’id, on prend la première team où il est owner/membre.
        $team = $teamId ? $teams->find($teamId) : null;
        if (!$team) {
            // ⚠️ adapte selon ton repo : ici on fait simple via DQL côté repo si tu veux
            // fallback: prendre la première team en DB où user est owner (sinon 403)
            $team = $teams->findOneBy(['owner' => $user]);
        }
        if (!$team) {
            throw $this->createAccessDeniedException('Aucune équipe trouvée.');
        }
$myTeams = $teams->createQueryBuilder('t')
    ->leftJoin('t.members', 'm')
    ->andWhere('t.owner = :u OR m.user = :u')
    ->setParameter('u', $user)
    ->orderBy('t.createdAt', 'DESC')
    ->getQuery()->getResult();

    $teamUsers = [];
if ($team) {
    $teamUsers[] = $team->getOwner();
    foreach ($team->getMembers() as $m) $teamUsers[] = $m->getUser();
    // unique par id
    $uniq = [];
    foreach ($teamUsers as $u) $uniq[$u->getId()] = $u;
    $teamUsers = array_values($uniq);
}
        // Sécurité : user doit être owner ou membre
        $isAllowed = ($team->getOwner()?->getId() === $user->getId());
        if (!$isAllowed) {
            foreach ($team->getMembers() as $m) {
                if ($m->getUser()->getId() === $user->getId()) { $isAllowed = true; break; }
            }
        }
        if (!$isAllowed) throw $this->createAccessDeniedException();

        $note = (new Note())->setTeam($team)->setCreatedBy($user);
        $form = $this->createForm(NoteType::class, $note);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $note->touch();
            $em->persist($note);
            $em->flush();

            $url = $this->generateUrl('app_note', ['teamId' => $team->getId()]);
            $notifier->notifyTeam(
                team: $team,
                actor: $user,
                title: 'Nouvelle note créée',
                message: $note->getTitle(),
                url: $url,
                entity: $note
            );

            $this->addFlash('success', 'Note créée ✅');
            return $this->redirectToRoute('app_note', ['teamId' => $team->getId()]);
        }

        $allNotes = $notes->findByTeam($team);

        // Group by status for UI columns
        $grouped = ['todo' => [], 'in_progress' => [], 'done' => []];
        foreach ($allNotes as $n) {
            $grouped[$n->getStatus()->value][] = $n;
        }

        return $this->render('note/index.html.twig', [
          'team' => $team,
  'myTeams' => $myTeams,
  'teamUsers' => $teamUsers,
  'form' => $form,
  'notesByStatus' => $grouped,
        ]);
    }

    #[Route('/app/note/{id}/toggle', name: 'app_note_toggle', methods: ['POST'])]
    public function toggle(Note $note, Request $request, EntityManagerInterface $em, NoteNotifier $notifier): Response
    {
        $this->denyAccessUnlessGranted('NOTE_EDIT', $note);
        if (!$this->isCsrfTokenValid('toggle_note_'.$note->getId(), (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // simple cycle: todo -> in_progress -> done -> todo
        $next = match ($note->getStatus()->value) {
            'todo' => \App\Enum\NoteStatus::IN_PROGRESS,
            'in_progress' => \App\Enum\NoteStatus::DONE,
            default => \App\Enum\NoteStatus::TODO,
        };
        $note->setStatus($next);
        $note->touch();
        $em->flush();

        $actor = $this->getUser(); \assert($actor instanceof \App\Entity\User);
        $url = $this->generateUrl('app_note', ['teamId' => $note->getTeam()->getId()]);

        $notifier->notifyAssignees(
            note: $note,
            actor: $actor,
            title: 'Statut de tâche mis à jour',
            message: $note->getTitle().' → '.$note->getStatus()->label(),
            url: $url
        );

        return $this->redirectToRoute('app_note', ['teamId' => $note->getTeam()->getId()]);
    }

    #[Route('/app/note/{id}/delete', name: 'app_note_delete', methods: ['POST'])]
    public function delete(Note $note, Request $request, EntityManagerInterface $em, NoteNotifier $notifier): Response
    {
        $this->denyAccessUnlessGranted('NOTE_DELETE', $note);
        if (!$this->isCsrfTokenValid('delete_note_'.$note->getId(), (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $team = $note->getTeam();
        $title = $note->getTitle();

        $em->remove($note);
        $em->flush();

        $actor = $this->getUser(); \assert($actor instanceof \App\Entity\User);
        $url = $this->generateUrl('app_note', ['teamId' => $team->getId()]);
        $notifier->notifyTeam($team, $actor, 'Note supprimée', $title, $url, null);

        $this->addFlash('success', 'Supprimé 🗑️');
        return $this->redirectToRoute('app_note', ['teamId' => $team->getId()]);
    }

    #[Route('/app/note/{id}/assign', name: 'app_note_assign', methods: ['POST'])]
    public function assign(
        Note $note,
        Request $request,
        UserRepository $users,
        EntityManagerInterface $em,
        NoteNotifier $notifier
    ): Response {
        $this->denyAccessUnlessGranted('NOTE_ASSIGN', $note);
        if (!$this->isCsrfTokenValid('assign_note_'.$note->getId(), (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $assigneeId = (int)$request->request->get('assignee_id');
        $assignee = $users->find($assigneeId);
        if (!$assignee) {
            $this->addFlash('danger', 'Utilisateur introuvable.');
            return $this->redirectToRoute('app_note', ['teamId' => $note->getTeam()->getId()]);
        }

        // (Option) Vérifier que assignee est dans la team
        $team = $note->getTeam();
        $isInTeam = ($team->getOwner()?->getId() === $assignee->getId());
        if (!$isInTeam) {
            foreach ($team->getMembers() as $m) {
                if ($m->getUser()->getId() === $assignee->getId()) { $isInTeam = true; break; }
            }
        }
        if (!$isInTeam) {
            $this->addFlash('danger', 'Cet utilisateur n’est pas dans l’équipe.');
            return $this->redirectToRoute('app_note', ['teamId' => $team->getId()]);
        }

        // Create assignment (unique constraint prevents duplicates)
        $actor = $this->getUser(); \assert($actor instanceof \App\Entity\User);

        $assignment = (new NoteAssignment())
            ->setNote($note)
            ->setAssignee($assignee)
            ->setAssignedBy($actor);

        $note->addAssignment($assignment);
        $note->touch();

        $em->persist($assignment);
        $em->flush();

        $url = $this->generateUrl('app_note', ['teamId' => $team->getId()]);
        $notifier->notifyTeam($team, $actor, 'Tâche assignée', $note->getTitle().' → '.$assignee->getUserIdentifier(), $url, $note);

        $this->addFlash('success', 'Assigné ✅');
        return $this->redirectToRoute('app_note', ['teamId' => $team->getId()]);
    }

    #[Route('/app/note/{id}/status', name: 'app_note_status', methods: ['POST'])]
public function status(Note $note, Request $request, EntityManagerInterface $em, NoteNotifier $notifier): Response
{
    $this->denyAccessUnlessGranted('NOTE_STATUS', $note);

    if (!$this->isCsrfTokenValid('status_note_'.$note->getId(), (string)$request->request->get('_token'))) {
        throw $this->createAccessDeniedException();
    }

    $status = (string)$request->request->get('status');
    $enum = \App\Enum\NoteStatus::tryFrom($status);
    if (!$enum) {
        $this->addFlash('danger', 'Statut invalide.');
        return $this->redirectToRoute('app_note');
    }

    $note->setStatus($enum);
    $note->touch();
    $em->flush();

    $actor = $this->getUser(); \assert($actor instanceof \App\Entity\User);

    // notif assignees + team si besoin
    if ($note->getTeam()) {
        $url = $this->generateUrl('app_note', ['teamId' => $note->getTeam()->getId()]);
        $notifier->notifyAssignees($note, $actor, 'Statut mis à jour', $note->getTitle().' → '.$enum->label(), $url);
    }

    return $this->redirectToRoute('app_note', ['teamId' => $note->getTeam()?->getId()]);
}

#[Route('/app/note/{id}/invite', name: 'app_note_invite', methods: ['POST'])]
public function invite(
    Note $note,
    Request $request,
    UserRepository $users,
    EntityManagerInterface $em,
    NoteNotifier $notifier
): Response {
    // pour l’instant : seul créateur peut inviter
    $this->denyAccessUnlessGranted('NOTE_EDIT', $note);

    if (!$this->isCsrfTokenValid('invite_note_'.$note->getId(), (string)$request->request->get('_token'))) {
        throw $this->createAccessDeniedException();
    }

    $email = mb_strtolower(trim((string)$request->request->get('email')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->addFlash('danger', 'Email invalide.');
        return $this->redirectToRoute('app_note', ['teamId' => $note->getTeam()?->getId()]);
    }

    $actor = $this->getUser(); \assert($actor instanceof \App\Entity\User);

    // Si user existe : on peut l’assigner direct (ou l’ajouter en "collaborator" selon ton choix)
    $existing = $users->findOneBy(['email' => $email]);
    if ($existing) {
        // option: assignment direct
        $assignment = (new \App\Entity\NoteAssignment())
            ->setNote($note)
            ->setAssignee($existing)
            ->setAssignedBy($actor);

        $note->addAssignment($assignment);
        $note->touch();
        $em->persist($assignment);
        $em->flush();

        $notifier->notifyAssignees($note, $actor, 'Vous avez été ajouté à une tâche', $note->getTitle(), $this->generateUrl('app_note'));
        $this->addFlash('success', 'Utilisateur ajouté ✅');
        return $this->redirectToRoute('app_note', ['teamId' => $note->getTeam()?->getId()]);
    }

    // Sinon : invite pending
    $invite = (new \App\Entity\NoteInvite())
        ->setNote($note)
        ->setEmail($email)
        ->setInvitedBy($actor);

    $em->persist($invite);
    $em->flush();

    // Ici tu peux envoyer un email avec $invite->getToken()
    $this->addFlash('success', 'Invitation créée (pending) ✉️');

    return $this->redirectToRoute('app_note', ['teamId' => $note->getTeam()?->getId()]);
}


}
