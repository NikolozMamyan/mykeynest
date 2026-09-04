<?php

namespace App\Controller\Front;

use App\Entity\Credential;
use App\Entity\OrganizationMember;
use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Enum\OrganizationRole;
use App\Form\OrganizationInviteMemberType;
use App\Form\TeamAddCredentialsType;
use App\Form\TeamAddMemberType;
use App\Form\TeamType;
use App\Repository\CredentialRepository;
use App\Repository\TeamMemberRepository;
use App\Repository\TeamRepository;
use App\Service\MailerService;
use App\Service\OrganizationInvitationManager;
use App\Service\TeamNotifier;
use App\Service\TeamMemberAssignmentManager;
use App\Service\TeamCredentialPermissionManager;
use App\Service\SubscriptionPlanService;
use App\Service\OrganizationSeatManager;
use App\Service\StripeBillingService;
use App\Service\StripePlanCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/app/teams', name: 'app_team_')]
class TeamPageController extends AbstractController
{
    public function __construct(
        private readonly SubscriptionPlanService $subscriptionPlans,
        private readonly OrganizationSeatManager $organizationSeats,
    ) {
    }

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
            'Vous avez été invité dans une équipe MYKEYNEST',
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
    public function index(
        TeamRepository $teamRepository,
        Security $security,
        StripePlanCatalog $stripePlans,
    ): Response
    {
        $user = $security->getUser();
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $teams = $teamRepository->findByUser($user);
        $ownedTeamsCount = $teamRepository->count(['owner' => $user]);
        $currentPlan = $this->subscriptionPlans->getPlanForUser($user);
        $organization = $this->organizationSeats->getOrganizationForUser($user);
        $canManageOrganization = $organization ? $this->organizationSeats->canManage($organization, $user) : false;
        $teamLimit = $canManageOrganization && $organization?->isActive()
            ? null
            : $this->subscriptionPlans->getPersonalLimit($user, SubscriptionPlanService::LIMIT_TEAMS);
        $organizationInviteForm = null;
        if ($organization?->isActive() && $canManageOrganization) {
            $organizationInviteForm = $this->createForm(OrganizationInviteMemberType::class, null, [
                'action' => $this->generateUrl('app_team_company_member_invite'),
                'method' => 'POST',
            ])->createView();
        }

        return $this->render('team/index.html.twig', [
            'teams' => $teams,
            'currentPlan' => $currentPlan,
            'ownedTeamsCount' => $ownedTeamsCount,
            'teamLimit' => $teamLimit,
            'canCreateTeam' => $teamLimit === null || $ownedTeamsCount < $teamLimit,
            'organization' => $organization,
            'organizationMembership' => $organization ? $this->organizationSeats->getMembership($organization, $user) : null,
            'canManageOrganization' => $canManageOrganization,
            'organizationInviteForm' => $organizationInviteForm,
            'seatSummary' => $organization ? $this->organizationSeats->getSeatSummary($organization) : null,
            'seatMinimum' => $stripePlans->getTeamMinimumSeats(),
            'seatMaximum' => $stripePlans->getTeamMaximumSeats(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        Security $security,
        TeamCredentialPermissionManager $permissionManager,
    ): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        /** @var User|null $user */
        $user = $security->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $organization = $this->organizationSeats->getOrganizationForUser($user);
        $teamLimit = $organization && $organization->isActive() && $this->organizationSeats->canManage($organization, $user)
            ? null
            : $this->subscriptionPlans->getPersonalLimit($user, SubscriptionPlanService::LIMIT_TEAMS);
        $countTeams = $em->getRepository(Team::class)->count(['owner' => $user]);

        if ($teamLimit !== null && $countTeams >= $teamLimit) {
            $this->addFlash('warning', sprintf('Limite atteinte : %d équipes maximum avec votre plan.', $teamLimit));

            return $this->redirectToRoute('app_team_index');
        }

        $team = new Team();

        $form = $this->createForm(TeamType::class, $team, [
            'user' => $user,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $isCompanyTeam = $organization
                && $organization->isActive()
                && $this->organizationSeats->canManage($organization, $user);
            $teamOwner = $isCompanyTeam ? $organization->getOwner() : $user;
            if (!$teamOwner instanceof User) {
                throw new \LogicException('Le propriétaire du groupe est introuvable.');
            }

            $team->setOwner($teamOwner);
            if ($isCompanyTeam) {
                $team->setOrganization($organization);
            }
            $canRevealPassword = (bool) $form->get('canRevealPassword')->getData();

            $ownerMember = (new TeamMember())
                ->setTeam($team)
                ->setUser($teamOwner)
                ->setRole(TeamRole::OWNER);

            $em->persist($team);
            $em->persist($ownerMember);

            if ($teamOwner !== $user) {
                $creatorMember = (new TeamMember())
                    ->setTeam($team)
                    ->setUser($user)
                    ->setRole(TeamRole::ADMIN);
                $em->persist($creatorMember);
            }

            foreach ($team->getCredentials() as $credential) {
                $permissionManager->setPasswordReveal($team, $credential, $canRevealPassword);
            }

            $em->flush();

            $this->addFlash('success', 'Groupe créé avec succès.');

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
        EntityManagerInterface $em,
        Security $security,
        MailerService $mailer,
        LoggerInterface $logger,
        UrlGeneratorInterface $urlGenerator,
        TeamNotifier $teamNotifier,
        TeamMemberAssignmentManager $memberAssignments,
        TeamCredentialPermissionManager $permissionManager,
    ): Response {
        $this->denyAccessUnlessGranted('TEAM_VIEW', $team);

        $user = $security->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $addMemberFormView = null;
        $addCredentialsFormView = null;

        if ($this->isGranted('TEAM_MANAGE', $team)) {
            $memberForm = $this->createForm(TeamAddMemberType::class, null, [
                'company_team' => $team->getOrganization() !== null,
            ]);
            $memberForm->handleRequest($request);

            if ($memberForm->isSubmitted() && $memberForm->isValid()) {
                $data = $memberForm->getData();
                $email = (string) $data['email'];
                /** @var TeamRole $role */
                $role = $data['role'];
                $membershipType = $memberForm->has('membershipType')
                    ? (string) $memberForm->get('membershipType')->getData()
                    : 'employee';
                try {
                    $assignment = $memberAssignments->assign(
                        $team,
                        $email,
                        $role,
                        $user,
                        $membershipType === 'guest',
                    );
                } catch (\DomainException|\InvalidArgumentException|\LogicException $exception) {
                    $this->addFlash('warning', $exception->getMessage());

                    return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
                }

                $member = $assignment['member'];
                $memberUser = $assignment['user'];
                $guestInvitationExpiresAt = $assignment['invitationExpiresAt'];
                $teamNotifier->notifyMemberAdded($team, $memberUser, $user, $member->getRole());

                if ($guestInvitationExpiresAt instanceof \DateTimeImmutable) {
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
                $canRevealPassword = (bool) $credentialsForm->get('canRevealPassword')->getData();
                $addedCredentials = [];

                foreach ($credentials as $credential) {
                    if (!$team->getCredentials()->contains($credential)) {
                        $team->addCredential($credential);
                        $permissionManager->setPasswordReveal($team, $credential, $canRevealPassword);
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
            'seatSummary' => $team->getOrganization() ? $this->organizationSeats->getSeatSummary($team->getOrganization()) : null,
        ]);
    }

    #[Route('/company/members/invite', name: 'company_member_invite', methods: ['POST'])]
    public function inviteCompanyMember(
        Request $request,
        OrganizationInvitationManager $invitations,
        TranslatorInterface $translator,
    ): Response {
        /** @var User|null $actor */
        $actor = $this->getUser();
        $organization = $actor instanceof User ? $this->organizationSeats->getOrganizationForUser($actor) : null;
        if (
            !$actor instanceof User
            || !$organization?->isActive()
            || !$this->organizationSeats->canManage($organization, $actor)
        ) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(OrganizationInviteMemberType::class, null, [
            'action' => $this->generateUrl('app_team_company_member_invite'),
            'method' => 'POST',
        ]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('warning', $translator->trans('teams.organization.invite.invalid'));

            return $this->redirectToRoute('app_team_index');
        }

        $data = $form->getData();
        try {
            $result = $invitations->inviteEmployee(
                $organization,
                (string) $data['email'],
                OrganizationRole::MEMBER,
                $actor,
            );
            $message = $result['pending']
                ? 'teams.organization.invite.success_pending'
                : 'teams.organization.invite.success_active';
            $this->addFlash('success', $translator->trans($message));
            if (!$result['mailSent']) {
                $this->addFlash('warning', $translator->trans('teams.organization.invite.mail_warning'));
            }
        } catch (\DomainException|\InvalidArgumentException|\LogicException $exception) {
            $this->addFlash('warning', $exception->getMessage());
        }

        return $this->redirectToRoute('app_team_index');
    }

    #[Route('/company/members/{id}/remove', name: 'company_member_remove', methods: ['POST'])]
    public function removeCompanyMember(
        OrganizationMember $membership,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User|null $actor */
        $actor = $this->getUser();
        $organization = $membership->getOrganization();
        if (!$actor instanceof User || !$organization || !$this->organizationSeats->canManage($organization, $actor)) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('remove_company_member_' . $membership->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF invalide.');

            return $this->redirectToRoute('app_team_index');
        }

        try {
            $this->organizationSeats->removeMembership($membership);
            $entityManager->flush();
            $this->addFlash('success', 'Le membre a été retiré de l’entreprise et de ses équipes.');
        } catch (\DomainException $exception) {
            $this->addFlash('warning', $exception->getMessage());
        }

        return $this->redirectToRoute('app_team_index');
    }

    #[Route('/company/members/{id}/role', name: 'company_member_role', methods: ['POST'])]
    public function updateCompanyMemberRole(
        OrganizationMember $membership,
        Request $request,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ): Response {
        /** @var User|null $actor */
        $actor = $this->getUser();
        $organization = $membership->getOrganization();
        if (!$actor instanceof User || !$organization || $organization->getOwner()?->getId() !== $actor->getId()) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('update_company_role_' . $membership->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', $translator->trans('teams.organization.invalid_csrf'));

            return $this->redirectToRoute('app_team_index');
        }

        $role = OrganizationRole::tryFrom(mb_strtoupper($request->request->getString('role')));
        try {
            if (!$role instanceof OrganizationRole) {
                throw new \InvalidArgumentException($translator->trans('teams.organization.invalid_role'));
            }

            $this->organizationSeats->changeManagementRole($membership, $actor, $role);
            $entityManager->flush();
            $this->addFlash('success', $translator->trans('teams.organization.role_updated'));
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('warning', $exception->getMessage());
        }

        return $this->redirectToRoute('app_team_index');
    }

    #[Route('/company/seats', name: 'company_seats_update', methods: ['POST'])]
    public function updateCompanySeats(
        Request $request,
        StripeBillingService $stripeBilling,
        LoggerInterface $logger,
        TranslatorInterface $translator,
    ): Response {
        /** @var User|null $owner */
        $owner = $this->getUser();
        $organization = $owner instanceof User ? $this->organizationSeats->getOrganizationForUser($owner) : null;
        if (!$owner instanceof User || !$organization || $organization->getOwner()?->getId() !== $owner->getId()) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('update_company_seats_' . $organization->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF invalide.');

            return $this->redirectToRoute('app_team_index');
        }

        $quantity = $request->request->getInt('quantity');
        try {
            $this->organizationSeats->assertQuantityCanBeReducedTo($organization, $quantity);
            $updatedQuantity = $stripeBilling->updateTeamSeatQuantity($owner, $quantity);
            $this->addFlash('success', sprintf('Votre abonnement Team comprend maintenant %d licences.', $updatedQuantity));
        } catch (\LogicException|\RuntimeException $exception) {
            $this->addFlash('warning', $exception->getMessage());
        } catch (\Throwable $exception) {
            $logger->error('Unable to update Stripe Team seat quantity.', [
                'organization_id' => $organization->getId(),
                'owner_id' => $owner->getId(),
                'exception' => $exception->getMessage(),
            ]);
            $this->addFlash('warning', $translator->trans('teams.organization.billing_error'));
        }

        return $this->redirectToRoute('app_team_index');
    }

    #[Route('/{id}/members/{memberId}/remove', name: 'remove_member', methods: ['POST'])]
    public function removeMember(
        Team $team,
        int $memberId,
        Request $request,
        TeamMemberRepository $teamMemberRepository,
        EntityManagerInterface $em,
        TeamNotifier $teamNotifier,
        TeamMemberAssignmentManager $memberAssignments,
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
        $memberAssignments->removeAssignment($member);
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
        TeamNotifier $teamNotifier,
        TeamMemberAssignmentManager $memberAssignments,
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

        $memberAssignments->removeAssignment($member);
        $em->flush();
        $teamNotifier->notifyMemberLeft($team, $user);

        $this->addFlash('success', sprintf('Vous avez quitte l equipe "%s".', $teamName));

        return $this->redirectToRoute('app_team_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(
        Team $team,
        Request $request,
        EntityManagerInterface $em,
        TeamMemberAssignmentManager $memberAssignments,
    ): Response {
        $this->denyAccessUnlessGranted('TEAM_DELETE', $team);

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete_team_' . $team->getId(), $token)) {
            $this->addFlash('danger', 'Token CSRF invalide.');

            return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
        }

        $teamName = $team->getName();

        foreach ($team->getMembers() as $member) {
            $memberAssignments->removeAssignment($member);
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
        EntityManagerInterface $em,
        TeamCredentialPermissionManager $permissionManager,
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

        $permissionManager->remove($team, $credential);
        $team->removeCredential($credential);
        $em->flush();

        $this->addFlash('success', 'Credential retire de l equipe avec succes.');

        return $this->redirectToRoute('app_team_show', ['id' => $team->getId()]);
    }
}
