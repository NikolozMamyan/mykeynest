<?php

namespace App\Controller\Front;

use App\Entity\Credential;
use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Form\TeamAddCredentialsType;
use App\Form\TeamAddMemberType;
use App\Form\TeamType;
use App\Repository\CredentialRepository;
use App\Repository\TeamMemberRepository;
use App\Repository\TeamRepository;
use App\Repository\UserRepository;
use App\Service\MailerService;
use App\Service\TeamNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/app/teams', name: 'app_team_')]
class TeamPageController extends AbstractController
{
    private function sendTeamGuestInvitationEmail(
        MailerService $mailer,
        UrlGeneratorInterface $urlGenerator,
        User $guest,
        Team $team,
        \DateTimeImmutable $expiresAt
    ): void {
        $guestRegisterUrl = $urlGenerator->generate(
            'app_guest_register',
            ['token' => $guest->getApiToken(), 'email' => $guest->getEmail()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $mailer->send(
            $guest->getEmail(),
            'Vous avez ete invite dans une equipe MYKEYNEST',
            'emails/team_invitation.html.twig',
            [
                'user' => $guest,
                'team' => $team,
                'guest_register' => $guestRegisterUrl,
                'expiresAt' => $expiresAt,
            ]
        );
    }

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

        /** @var User|null $user */
        $user = $security->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $hasSubscription = $user->hasActiveSubscription();

        if (!$hasSubscription) {
            $countTeams = $em->getRepository(Team::class)->count(['owner' => $user]);

            if ($countTeams >= 1) {
                $this->addFlash('warning', 'Limite atteinte : 1 equipes maximum sans abonnement.');

                return $this->redirectToRoute('app_team_index');
            }
        }

        $team = new Team();

        $form = $this->createForm(TeamType::class, $team, [
            'user' => $user,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $team->setOwner($user);

            $member = new TeamMember();
            $member->setTeam($team);
            $member->setUser($user);
            $member->setRole(TeamRole::OWNER);

            $em->persist($team);
            $em->persist($member);
            $em->flush();

            $this->addFlash('success', 'Equipe creee avec succes.');

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
        Security $security,
        MailerService $mailer,
        LoggerInterface $logger,
        UrlGeneratorInterface $urlGenerator,
        TeamNotifier $teamNotifier
    ): Response {
        $this->denyAccessUnlessGranted('TEAM_VIEW', $team);

        $user = $security->getUser();

        $addMemberFormView = null;
        $addCredentialsFormView = null;

        if ($this->isGranted('TEAM_MANAGE', $team)) {
            $memberForm = $this->createForm(TeamAddMemberType::class);
            $memberForm->handleRequest($request);

            if ($memberForm->isSubmitted() && $memberForm->isValid()) {
                $data = $memberForm->getData();
                $email = (string) $data['email'];
                /** @var TeamRole $role */
                $role = $data['role'];

                $memberUser = $userRepository->findOneBy(['email' => $email]);
                $isGuestInvitation = false;
                $guestInvitationExpiresAt = null;

                if (!$memberUser) {
                    $isGuestInvitation = true;
                    $guestInvitationExpiresAt = new \DateTimeImmutable('+24 hours');

                    $memberUser = new User();
                    $memberUser->setEmail($email);
                    $memberUser->setCompany('');
                    $memberUser->setPassword('');
                    $memberUser->setRoles(['ROLE_GUEST']);
                    $memberUser->setApiToken(bin2hex(random_bytes(32)));
                    $memberUser->setTokenExpiresAt($guestInvitationExpiresAt);
                    $memberUser->regenerateApiExtensionToken();

                    $em->persist($memberUser);
                } elseif (in_array('ROLE_GUEST', $memberUser->getRoles(), true)) {
                    $isGuestInvitation = true;
                    $guestInvitationExpiresAt = new \DateTimeImmutable('+24 hours');
                    $memberUser->setApiToken(bin2hex(random_bytes(32)));
                    $memberUser->setTokenExpiresAt($guestInvitationExpiresAt);
                }

                if ($team->getOwner()?->getId() === $memberUser->getId()) {
                    $this->addFlash('warning', 'Le proprietaire est deja membre de cette equipe.');

                    return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
                }

                $existing = $teamMemberRepository->findOneBy([
                    'team' => $team,
                    'user' => $memberUser,
                ]);

                if ($existing) {
                    $this->addFlash('warning', 'Cet utilisateur est deja membre de cette equipe.');

                    return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
                }

                $member = new TeamMember();
                $member->setTeam($team);
                $member->setUser($memberUser);
                $member->setRole($role);

                $em->persist($member);
                $em->flush();
                if ($user instanceof User) {
                    $teamNotifier->notifyMemberAdded($team, $memberUser, $user, $role);
                }

                if ($isGuestInvitation && $guestInvitationExpiresAt instanceof \DateTimeImmutable) {
                    try {
                        $this->sendTeamGuestInvitationEmail($mailer, $urlGenerator, $memberUser, $team, $guestInvitationExpiresAt);
                        $this->addFlash('success', 'Invitation envoyee et membre ajoute a l equipe.');
                    } catch (\Throwable $exception) {
                        $logger->warning('Unable to send team invitation email.', [
                            'guest_email' => $memberUser->getEmail(),
                            'team_id' => $team->getId(),
                            'exception' => $exception->getMessage(),
                        ]);
                        $this->addFlash('warning', 'Membre ajoute, mais impossible d envoyer l invitation email.');
                    }
                } else {
                    $this->addFlash('success', 'Membre ajoute avec succes a l equipe.');
                }

                return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
            }

            $credentialsForm = $this->createForm(TeamAddCredentialsType::class, null, [
                'user' => $user,
                'team' => $team,
            ]);
            $credentialsForm->handleRequest($request);

            if ($credentialsForm->isSubmitted() && $credentialsForm->isValid()) {
                $data = $credentialsForm->getData();
                $credentials = $data['credentials'];
                $addedCredentials = [];

                foreach ($credentials as $credential) {
                    if (!$team->getCredentials()->contains($credential)) {
                        $team->addCredential($credential);
                        $addedCredentials[] = $credential;
                    }
                }

                $em->flush();
                if ($user instanceof User) {
                    $teamNotifier->notifyCredentialsAdded($team, $user, $addedCredentials);
                }

                $this->addFlash('success', 'Credentials ajoutes avec succes a l equipe.');

                return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
            }

            $addMemberFormView = $memberForm->createView();
            $addCredentialsFormView = $credentialsForm->createView();
        }

        return $this->render('team/show.html.twig', [
            'team' => $team,
            'add_member_form' => $addMemberFormView,
            'add_credentials_form' => $addCredentialsFormView,
        ]);
    }

    #[Route('/{id}/members/{memberId}/remove', name: 'remove_member', methods: ['POST'])]
    public function removeMember(
        Team $team,
        int $memberId,
        Request $request,
        TeamMemberRepository $teamMemberRepository,
        EntityManagerInterface $em,
        TeamNotifier $teamNotifier
    ): Response {
        $this->denyAccessUnlessGranted('TEAM_MANAGE', $team);

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('remove_member_' . $memberId, $token)) {
            $this->addFlash('danger', 'Token CSRF invalide.');

            return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
        }

        $member = $teamMemberRepository->find($memberId);

        if (!$member || $member->getTeam()->getId() !== $team->getId()) {
            $this->addFlash('danger', 'Membre introuvable dans cette equipe.');

            return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
        }

        if ($member->getRole() === TeamRole::OWNER) {
            $this->addFlash('warning', 'Vous ne pouvez pas supprimer le proprietaire de l equipe.');

            return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
        }

        $actor = $this->getUser();
        $removedUser = $member->getUser();
        $em->remove($member);
        $em->flush();
        if ($actor instanceof User && $removedUser instanceof User) {
            $teamNotifier->notifyMemberRemoved($team, $removedUser, $actor);
        }

        $this->addFlash('success', 'Membre supprime de l equipe.');

        return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
    }

    #[Route('/{id}/leave', name: 'leave', methods: ['POST'])]
    public function leave(
        Team $team,
        Request $request,
        TeamMemberRepository $teamMemberRepository,
        EntityManagerInterface $em,
        Security $security,
        TeamNotifier $teamNotifier
    ): Response {
        $this->denyAccessUnlessGranted('TEAM_VIEW', $team);

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('leave_team_' . $team->getId(), $token)) {
            $this->addFlash('danger', 'Token CSRF invalide.');

            return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
        }

        /** @var User|null $user */
        $user = $security->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $member = $teamMemberRepository->findOneBy([
            'team' => $team,
            'user' => $user,
        ]);

        if (!$member) {
            $this->addFlash('warning', 'Vous n etes pas membre de cette equipe.');

            return $this->redirectToRoute('app_team_index');
        }

        if ($member->getRole() === TeamRole::OWNER) {
            $this->addFlash('warning', 'Le proprietaire ne peut pas quitter l equipe. Supprimez-la pour la fermer.');

            return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
        }

        $teamName = $team->getName() ?? 'cette equipe';

        $em->remove($member);
        $em->flush();
        $teamNotifier->notifyMemberLeft($team, $user);

        $this->addFlash('success', sprintf('Vous avez quitte l equipe "%s".', $teamName));

        return $this->redirectToRoute('app_team_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(
        Team $team,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $this->denyAccessUnlessGranted('TEAM_DELETE', $team);

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete_team_' . $team->getId(), $token)) {
            $this->addFlash('danger', 'Token CSRF invalide.');

            return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
        }

        $teamName = $team->getName();

        foreach ($team->getMembers() as $member) {
            $em->remove($member);
        }

        $em->remove($team);
        $em->flush();

        $this->addFlash('success', sprintf('L equipe "%s" a ete supprimee avec succes.', $teamName));

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

        if (!$credential instanceof Credential) {
            $this->addFlash('danger', 'Credential introuvable.');

            return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
        }

        if (!$team->getCredentials()->contains($credential)) {
            $this->addFlash('warning', 'Ce credential n est pas partage avec cette equipe.');

            return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
        }

        $team->removeCredential($credential);
        $em->flush();

        $this->addFlash('success', 'Credential retire de l equipe avec succes.');

        return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
    }
}
