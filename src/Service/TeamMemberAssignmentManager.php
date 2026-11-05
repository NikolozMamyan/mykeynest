<?php

namespace App\Service;

use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\User;
use App\Enum\OrganizationMemberStatus;
use App\Enum\OrganizationRole;
use App\Enum\TeamRole;
use App\Repository\TeamMemberRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

final class TeamMemberAssignmentManager
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TeamMemberRepository $teamMembers,
        private readonly OrganizationSeatManager $organizationSeats,
        private readonly EntityManagerInterface $entityManager,
        private readonly SubscriptionPlanService $subscriptionPlans,
    ) {
    }

    /**
     * @return array{member: TeamMember, user: User, invitationExpiresAt: ?\DateTimeImmutable}
     */
    public function assign(
        Team $team,
        string $email,
        TeamRole $role,
        User $actor,
    ): array {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Adresse e-mail invalide.');
        }
        if ($role === TeamRole::OWNER) {
            throw new \InvalidArgumentException('Le rôle propriétaire ne peut pas être attribué.');
        }

        $organization = $team->getOrganization();
        if ($organization !== null && !$organization->isActive()) {
            throw new \LogicException('L’espace entreprise est suspendu.');
        }
        $memberUser = $this->users->findOneBy(['email' => $email]);
        $invitationExpiresAt = null;

        if ($memberUser instanceof User) {
            if ($team->getOwner() === $memberUser || (
                $team->getOwner()?->getId() !== null
                && $team->getOwner()?->getId() === $memberUser->getId()
            )) {
                throw new \DomainException('Le propriétaire est déjà membre de cette équipe.');
            }

            if ($memberUser->getId() !== null && $this->teamMembers->findOneBy([
                'team' => $team,
                'user' => $memberUser,
            ]) instanceof TeamMember) {
                throw new \DomainException('Cet utilisateur est déjà membre de cette équipe.');
            }
        }

        $membership = $organization !== null && $memberUser instanceof User
            ? $this->organizationSeats->getMembership($organization, $memberUser)
            : null;
        if ($membership?->getStatus() === OrganizationMemberStatus::SUSPENDED) {
            throw new \DomainException('teams.wizard.member_suspended');
        }
        $hasCompanyLicense = $membership?->getStatus() === OrganizationMemberStatus::ACTIVE
            && $membership->getRole() !== OrganizationRole::GUEST;
        $needsLicense = !$hasCompanyLicense && (
            !$memberUser instanceof User
            || $this->subscriptionPlans->getPlanForUser($memberUser)['code'] === SubscriptionPlanService::PLAN_FREE
        );
        if ($needsLicense && $organization === null) {
            throw new \DomainException('teams.wizard.license_required');
        }

        if (!$memberUser instanceof User) {
            $invitationExpiresAt = new \DateTimeImmutable('+24 hours');
            $memberUser = $this->createGuestUser($email, $invitationExpiresAt);
        } elseif (in_array('ROLE_GUEST', $memberUser->getRoles(), true)) {
            $invitationExpiresAt = new \DateTimeImmutable('+24 hours');
            $memberUser
                ->setApiToken(bin2hex(random_bytes(32)))
                ->setTokenExpiresAt($invitationExpiresAt);
        }

        if ($needsLicense) {
            $membership = $this->organizationSeats->inviteCompanyMember(
                $organization,
                $memberUser,
                $actor,
                OrganizationRole::MEMBER,
                $invitationExpiresAt !== null,
                $invitationExpiresAt,
            );
            if ($invitationExpiresAt !== null) {
                $membership->setInvitationExpiresAt($invitationExpiresAt);
            }
        }

        $member = (new TeamMember())
            ->setTeam($team)
            ->setUser($memberUser)
            ->setRole($role);

        $this->entityManager->persist($member);
        $this->entityManager->flush();

        return [
            'member' => $member,
            'user' => $memberUser,
            'invitationExpiresAt' => $invitationExpiresAt,
        ];
    }

    public function removeAssignment(TeamMember $member): void
    {
        $team = $member->getTeam();
        $user = $member->getUser();
        $organization = $team?->getOrganization();
        if ($organization !== null && $user instanceof User) {
            $membership = $this->organizationSeats->getMembership($organization, $user);
            if ($membership?->getRole() === OrganizationRole::GUEST) {
                $this->organizationSeats->removeMembership($membership);

                return;
            }
        }

        $this->entityManager->remove($member);
    }

    private function createGuestUser(string $email, \DateTimeImmutable $expiresAt): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setCompany('')
            ->setPassword('')
            ->setRoles(['ROLE_GUEST'])
            ->setApiToken(bin2hex(random_bytes(32)))
            ->setTokenExpiresAt($expiresAt)
            ->regenerateApiExtensionToken();

        $this->entityManager->persist($user);

        return $user;
    }
}
