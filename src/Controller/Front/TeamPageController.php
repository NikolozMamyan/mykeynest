<?php

namespace App\Controller\Front;

use App\Entity\Team;
use App\Entity\TeamMember;
use App\Enum\TeamRole;
use App\Form\TeamType;
use App\Form\TeamAddMemberType;
use App\Repository\TeamMemberRepository;
use App\Repository\TeamRepository;
use App\Repository\UserRepository;
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
        $user = $security->getUser();
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

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
        EntityManagerInterface $em
    ): Response {
        $this->denyAccessUnlessGranted('TEAM_VIEW', $team);

        // Par défaut, pas de formulaire si l’utilisateur ne peut pas gérer l’équipe
        $addMemberFormView = null;

        if ($this->isGranted('TEAM_MANAGE', $team)) {
            $form = $this->createForm(TeamAddMemberType::class);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                $data  = $form->getData();
                $email = $data['email'];
                /** @var TeamRole $role */
                $role  = $data['role'];

                $user = $userRepository->findOneBy(['email' => $email]);

                if (!$user) {
                    $this->addFlash('danger', sprintf('Aucun utilisateur trouvé avec l’email "%s".', $email));
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

                        $this->addFlash('success', 'Membre ajouté avec succès à l’équipe.');
                    }
                }

                return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
            }

            $addMemberFormView = $form->createView();
        }

        return $this->render('team/show.html.twig', [
            'team'            => $team,
            'add_member_form' => $addMemberFormView,
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

        // On ne supprime pas l’OWNER
        if ($member->getRole() === TeamRole::OWNER) {
            $this->addFlash('warning', 'Vous ne pouvez pas supprimer le propriétaire de l’équipe.');
            return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
        }

        $em->remove($member);
        $em->flush();

        $this->addFlash('success', 'Membre supprimé de l’équipe.');
        return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
    }
}
