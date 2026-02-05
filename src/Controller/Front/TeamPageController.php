<?php

namespace App\Controller\Front;

use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\Credential;
use App\Enum\TeamRole;
use App\Form\TeamType;
use App\Form\TeamAddMemberType;
use App\Form\TeamAddCredentialsType;
use App\Repository\TeamMemberRepository;
use App\Repository\TeamRepository;
use App\Repository\UserRepository;
use App\Repository\CredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/app/teams', name: 'app_team_')]
class TeamPageController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(TeamRepository $teamRepository, Security $security): Response
    {
        $user = $security->getUser();
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $teams = $teamRepository->findByUser($user);

        return $this->render('team/index.html.twig', [
            'teams' => $teams,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
public function new(Request $request, EntityManagerInterface $em, Security $security): Response
{
    $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

    /** @var User $user */
    $user = $security->getUser();
    if (!$user) {
        throw $this->createAccessDeniedException();
    }

    // ✅ 1) Bloquer si pas d'abonnement et limite atteinte
    $hasSubscription = (bool) $user->isSubscribed(); // adapte: isSubscribed(), getSubscriptionActive(), etc.

    if (!$hasSubscription) {
        // Méthode A: via repository (recommandé)
        $countTeams = $em->getRepository(Team::class)->count(['owner' => $user]);
        // ou si ta Team est liée autrement, adapte le critère (ex: ['createdBy' => $user])

        if ($countTeams >= 1) {
            $this->addFlash('warning', 'Limite atteinte : 1 équipes maximum sans abonnement.');
            return $this->redirectToRoute('app_team_index');
        }
    }

    // ✅ 2) Création normale
    $team = new Team();

    $form = $this->createForm(TeamType::class, $team, [
        'user' => $user,
    ]);

    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        // owner
        $team->setOwner($user);

        // créateur = OWNER dans TeamMember
        $member = new TeamMember();
        $member->setTeam($team);
        $member->setUser($user);
        $member->setRole(TeamRole::OWNER);

        $em->persist($team);
        $em->persist($member);
        $em->flush();

        $this->addFlash('success', 'Équipe créée avec succès.');

        return $this->redirectToRoute('app_team_index');
    }

    return $this->render('team/new.html.twig', [
        'form' => $form->createView(),
    ]);
}

    #[Route('/{id}', name: 'show', methods: ['GET', 'POST'])]
    public function show(
        Team $team,
        Request $request,
        UserRepository $userRepository,
        TeamMemberRepository $teamMemberRepository,
        EntityManagerInterface $em,
        Security $security
    ): Response {
        $this->denyAccessUnlessGranted('TEAM_VIEW', $team);

        $user = $security->getUser();

        // Par défaut, pas de formulaire si l'utilisateur ne peut pas gérer l'équipe
        $addMemberFormView = null;
        $addCredentialsFormView = null;

        if ($this->isGranted('TEAM_MANAGE', $team)) {
            // Formulaire d'ajout de membre
            $memberForm = $this->createForm(TeamAddMemberType::class);
            $memberForm->handleRequest($request);

            if ($memberForm->isSubmitted() && $memberForm->isValid()) {
                $data  = $memberForm->getData();
                $email = $data['email'];
                /** @var TeamRole $role */
                $role  = $data['role'];

                $user = $userRepository->findOneBy(['email' => $email]);

                if (!$user) {
                    $this->addFlash('danger', sprintf('Aucun utilisateur trouvé avec lemail "%s".', $email));
                } else {
                    // Vérifier si déjà membre
                    $existing = $teamMemberRepository->findOneBy([
                        'team' => $team,
                        'user' => $user,
                    ]);

                    if ($existing) {
                        $this->addFlash('warning', 'Cet utilisateur est déjà membre de cette équipe.');
                    } else {
                        $member = new TeamMember();
                        $member->setTeam($team);
                        $member->setUser($user);
                        $member->setRole($role);

                        $em->persist($member);
                        $em->flush();

                        $this->addFlash('success', 'Membre ajouté avec succès à léquipe.');
                    }
                }

                return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
            }

            // Formulaire d'ajout de credentials
            $credentialsForm = $this->createForm(TeamAddCredentialsType::class, null, [
                'user' => $user,
                'team' => $team,
            ]);
            $credentialsForm->handleRequest($request);

            if ($credentialsForm->isSubmitted() && $credentialsForm->isValid()) {
                $data = $credentialsForm->getData();
                $credentials = $data['credentials'];

                foreach ($credentials as $credential) {
                    if (!$team->getCredentials()->contains($credential)) {
                        $team->addCredential($credential);
                    }
                }

                $em->flush();

                $this->addFlash('success', 'Credentials ajoutés avec succès à l\'équipe.');
                return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
            }

            $addMemberFormView = $memberForm->createView();
            $addCredentialsFormView = $credentialsForm->createView();
        }

        return $this->render('team/show.html.twig', [
            'team'                   => $team,
            'add_member_form'        => $addMemberFormView,
            'add_credentials_form'   => $addCredentialsFormView,
        ]);
    }

    #[Route('/{id}/members/{memberId}/remove', name: 'remove_member', methods: ['POST'])]
    public function removeMember(
        Team $team,
        int $memberId,
        Request $request,
        TeamMemberRepository $teamMemberRepository,
        EntityManagerInterface $em
    ): Response {
        $this->denyAccessUnlessGranted('TEAM_MANAGE', $team);

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('remove_member_' . $memberId, $token)) {
            $this->addFlash('danger', 'Token CSRF invalide.');
            return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
        }

        $member = $teamMemberRepository->find($memberId);

        if (!$member || $member->getTeam()->getId() !== $team->getId()) {
            $this->addFlash('danger', 'Membre introuvable dans cette équipe.');
            return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
        }

        // On ne supprime pas l'OWNER
        if ($member->getRole() === TeamRole::OWNER) {
            $this->addFlash('warning', 'Vous ne pouvez pas supprimer le propriétaire de léquipe.');
            return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
        }

        $em->remove($member);
        $em->flush();

        $this->addFlash('success', 'Membre supprimé de léquipe.');
        return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(
        Team $team,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        // Seul le propriétaire peut supprimer l'équipe
        $this->denyAccessUnlessGranted('TEAM_DELETE', $team);

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete_team_' . $team->getId(), $token)) {
            $this->addFlash('danger', 'Token CSRF invalide.');
            return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
        }

        $teamName = $team->getName();

        // Supprimer tous les membres
        foreach ($team->getMembers() as $member) {
            $em->remove($member);
        }

        // Supprimer l'équipe
        $em->remove($team);
        $em->flush();

        $this->addFlash('success', sprintf('L\'équipe "%s" a été supprimée avec succès.', $teamName));
        return $this->redirectToRoute('app_team_index');
    }

    #[Route('/{id}/credentials/{credentialId}/remove', name: 'remove_credential', methods: ['POST'])]
    public function removeCredential(
        Team $team,
        int $credentialId,
        Request $request,
        CredentialRepository $credentialRepository,
        EntityManagerInterface $em
    ): Response {
        $this->denyAccessUnlessGranted('TEAM_MANAGE', $team);

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('remove_credential_' . $credentialId, $token)) {
            $this->addFlash('danger', 'Token CSRF invalide.');
            return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
        }

        $credential = $credentialRepository->find($credentialId);

        if (!$credential) {
            $this->addFlash('danger', 'Credential introuvable.');
            return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
        }

        if (!$team->getCredentials()->contains($credential)) {
            $this->addFlash('warning', 'Ce credential n\'est pas partagé avec cette équipe.');
            return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
        }

        $team->removeCredential($credential);
        $em->flush();

        $this->addFlash('success', 'Credential retiré de l\'équipe avec succès.');
        return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
    }
}