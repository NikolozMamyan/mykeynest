<?php

namespace App\Service;

use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Entity\User;
use App\Entity\UserSubscription;
use App\Enum\OrganizationMemberStatus;
use App\Enum\OrganizationRole;
use App\Enum\OrganizationStatus;
use App\Repository\OrganizationMemberRepository;
use App\Repository\OrganizationRepository;
use App\Repository\TeamRepository;
use Doctrine\ORM\EntityManagerInterface;

final class OrganizationProvisioner
{
    public function __construct(
        private readonly OrganizationRepository $organizations,
        private readonly OrganizationMemberRepository $members,
        private readonly TeamRepository $teams,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Keep the company workspace aligned with a user's Team subscription.
     * The caller owns the transaction and flush.
     */
    public function synchronize(User $user, UserSubscription $subscription): ?Organization
    {
        $isActiveTeam = $subscription->isActive()
            && mb_strtolower((string) $subscription->getPlanCode()) === SubscriptionPlanService::PLAN_TEAM;
        $organization = $subscription->getOrganization();

        if (!$isActiveTeam) {
            $organization?->setStatus(OrganizationStatus::SUSPENDED);

            return $organization;
        }

        $organization ??= $this->organizations->findOwnedBy($user);
        if (!$organization instanceof Organization) {
            $organization = (new Organization())
                ->setName($this->resolveOrganizationName($user))
                ->setOwner($user);
            $this->entityManager->persist($organization);
        }

        $organization
            ->setStatus(OrganizationStatus::ACTIVE)
            ->setSubscription($subscription);
        $subscription->setOrganization($organization);

        $ownerMembership = $this->members->findMembership($organization, $user);
        if (!$ownerMembership instanceof OrganizationMember) {
            $ownerMembership = (new OrganizationMember())
                ->setUser($user)
                ->setRole(OrganizationRole::OWNER)
                ->setStatus(OrganizationMemberStatus::ACTIVE)
                ->activate();
            $organization->addMember($ownerMembership);
            $this->entityManager->persist($ownerMembership);
        } else {
            $ownerMembership
                ->setRole(OrganizationRole::OWNER)
                ->activate();
        }

        foreach ($this->teams->findBy(['owner' => $user, 'organization' => null]) as $team) {
            $organization->addTeam($team);

            foreach ($team->getMembers() as $teamMember) {
                $memberUser = $teamMember->getUser();
                if (!$memberUser instanceof User || $memberUser === $user) {
                    continue;
                }

                $membership = $this->findMembership($organization, $memberUser);
                if ($membership instanceof OrganizationMember) {
                    continue;
                }

                $isPendingGuest = in_array('ROLE_GUEST', $memberUser->getRoles(), true);
                $membership = (new OrganizationMember())
                    ->setUser($memberUser)
                    ->setInvitedBy($user)
                    ->setRole($isPendingGuest ? OrganizationRole::GUEST : OrganizationRole::MEMBER)
                    ->setStatus($isPendingGuest ? OrganizationMemberStatus::PENDING : OrganizationMemberStatus::ACTIVE)
                    ->setInvitationExpiresAt($isPendingGuest ? $memberUser->getTokenExpiresAt() : null);

                if (!$isPendingGuest) {
                    $membership->activate();
                }

                $organization->addMember($membership);
                $this->entityManager->persist($membership);
            }
        }

        return $organization;
    }

    private function findMembership(Organization $organization, User $user): ?OrganizationMember
    {
        foreach ($organization->getMembers() as $membership) {
            if ($membership->getUser() === $user) {
                return $membership;
            }

            if (
                $membership->getUser()?->getId() !== null
                && $membership->getUser()?->getId() === $user->getId()
            ) {
                return $membership;
            }
        }

        return $this->members->findMembership($organization, $user);
    }

    private function resolveOrganizationName(User $user): string
    {
        $company = trim((string) $user->getCompany());
        if ($company !== '') {
            return mb_substr($company, 0, 180);
        }

        $email = (string) $user->getEmail();
        $domain = str_contains($email, '@') ? explode('@', $email, 2)[1] : '';
        $name = $domain !== '' ? explode('.', $domain, 2)[0] : 'Mon entreprise';

        return mb_substr(mb_convert_case($name, MB_CASE_TITLE, 'UTF-8'), 0, 180);
    }
}
