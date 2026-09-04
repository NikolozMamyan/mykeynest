<?php

namespace App\Service;

use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\OrganizationMemberStatus;
use App\Enum\OrganizationRole;
use App\Repository\OrganizationMemberRepository;
use App\Repository\OrganizationRepository;
use App\Repository\TeamMemberRepository;
use Doctrine\ORM\EntityManagerInterface;

final class OrganizationSeatManager
{
    public const INCLUDED_GUESTS = 5;

    public function __construct(
        private readonly OrganizationRepository $organizations,
        private readonly OrganizationMemberRepository $members,
        private readonly TeamMemberRepository $teamMembers,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getOrganizationForUser(User $user): ?Organization
    {
        $owned = $user->getUserSubscription()?->getOrganization()
            ?? $this->organizations->findOwnedBy($user);
        if ($owned instanceof Organization) {
            return $owned;
        }

        return $this->members->findOneBy([
            'user' => $user,
            'status' => OrganizationMemberStatus::ACTIVE,
        ])?->getOrganization();
    }

    public function getMembership(Organization $organization, User $user): ?OrganizationMember
    {
        foreach ($organization->getMembers() as $membership) {
            $memberUser = $membership->getUser();
            if ($memberUser === $user || (
                $memberUser?->getId() !== null
                && $memberUser->getId() === $user->getId()
            )) {
                return $membership;
            }
        }

        if ($user->getId() === null) {
            return null;
        }

        return $this->members->findMembership($organization, $user);
    }

    public function canManage(Organization $organization, User $user): bool
    {
        $owner = $organization->getOwner();
        if ($owner === $user || ($owner?->getId() !== null && $owner->getId() === $user->getId())) {
            return true;
        }

        $membership = $this->getMembership($organization, $user);

        return $membership?->getStatus() === OrganizationMemberStatus::ACTIVE
            && $membership->getRole()->canManageMembers();
    }

    /**
     * @return array{
     *   purchased:int,
     *   used:int,
     *   active:int,
     *   pending:int,
     *   available:int,
     *   guests:int,
     *   guestLimit:int,
     *   overCapacity:bool
     * }
     */
    public function getSeatSummary(Organization $organization): array
    {
        $purchased = max(0, $organization->getSubscription()?->getQuantity() ?? 0);
        $used = 0;
        $active = 0;
        $pending = 0;
        $guests = 0;

        foreach ($organization->getMembers() as $member) {
            if ($member->getRole() === OrganizationRole::GUEST) {
                if ($member->getStatus()->reservesSeat() && !$member->isInvitationExpired()) {
                    $guests++;
                }
                continue;
            }

            if (!$member->consumesSeat()) {
                continue;
            }

            $used++;
            if ($member->getStatus() === OrganizationMemberStatus::ACTIVE) {
                $active++;
            } elseif ($member->getStatus() === OrganizationMemberStatus::PENDING) {
                $pending++;
            }
        }

        return [
            'purchased' => $purchased,
            'used' => $used,
            'active' => $active,
            'pending' => $pending,
            'available' => max(0, $purchased - $used),
            'guests' => $guests,
            'guestLimit' => self::INCLUDED_GUESTS,
            'overCapacity' => $used > $purchased,
        ];
    }

    public function inviteCompanyMember(
        Organization $organization,
        User $user,
        User $invitedBy,
        OrganizationRole $role = OrganizationRole::MEMBER,
        bool $pending = false,
        ?\DateTimeImmutable $expiresAt = null,
    ): OrganizationMember {
        if (!$organization->isActive()) {
            throw new \LogicException('L’espace entreprise est suspendu.');
        }
        if (!in_array($role, [OrganizationRole::ADMIN, OrganizationRole::MEMBER], true)) {
            throw new \InvalidArgumentException('Seuls les rôles administrateur et membre peuvent utiliser une licence.');
        }
        if (!$this->canManage($organization, $invitedBy)) {
            throw new \DomainException('Seul un administrateur de l’entreprise peut inviter un nouveau membre.');
        }
        $owner = $organization->getOwner();
        $invitedByOwner = $owner === $invitedBy
            || ($owner?->getId() !== null && $owner->getId() === $invitedBy->getId());
        if ($role === OrganizationRole::ADMIN && !$invitedByOwner) {
            throw new \DomainException('Seul le propriétaire peut inviter un administrateur.');
        }

        $existing = $this->getMembership($organization, $user);
        if (
            $existing instanceof OrganizationMember
            && !$existing->isInvitationExpired()
            && $existing->getRole() !== OrganizationRole::GUEST
        ) {
            if (!$pending && $existing->getStatus() === OrganizationMemberStatus::PENDING) {
                $existing->activate();
            }

            return $existing;
        }

        $summary = $this->getSeatSummary($organization);
        $existingUsesSeat = $existing?->consumesSeat() ?? false;
        if (!$existingUsesSeat && $summary['used'] >= $summary['purchased']) {
            throw new \DomainException('Toutes les licences sont attribuées. Ajoutez une licence avant cette invitation.');
        }

        $membership = $existing ?? new OrganizationMember();
        $membership
            ->setOrganization($organization)
            ->setUser($user)
            ->setInvitedBy($invitedBy)
            ->setRole($role)
            ->setInvitationExpiresAt($pending ? $expiresAt : null)
            ->setStatus($pending ? OrganizationMemberStatus::PENDING : OrganizationMemberStatus::ACTIVE);

        if (!$pending) {
            $membership->activate();
        }

        $organization->addMember($membership);
        $this->entityManager->persist($membership);

        return $membership;
    }

    public function inviteExternalGuest(
        Organization $organization,
        User $user,
        User $invitedBy,
        bool $pending = false,
        ?\DateTimeImmutable $expiresAt = null,
    ): OrganizationMember {
        if (!$organization->isActive()) {
            throw new \LogicException('L’espace entreprise est suspendu.');
        }

        $existing = $this->getMembership($organization, $user);
        if ($existing instanceof OrganizationMember && $existing->getRole() !== OrganizationRole::GUEST) {
            throw new \DomainException('Cet utilisateur est déjà collaborateur de l’entreprise. Ajoutez-le comme collaborateur.');
        }
        if ($existing instanceof OrganizationMember && !$existing->isInvitationExpired()) {
            if (!$pending && $existing->getStatus() === OrganizationMemberStatus::PENDING) {
                $existing->activate();
            }

            return $existing;
        }

        $summary = $this->getSeatSummary($organization);
        if ($existing === null && $summary['guests'] >= self::INCLUDED_GUESTS) {
            throw new \DomainException(sprintf('La limite de %d invités externes est atteinte.', self::INCLUDED_GUESTS));
        }

        $membership = $existing ?? new OrganizationMember();
        $membership
            ->setOrganization($organization)
            ->setUser($user)
            ->setInvitedBy($invitedBy)
            ->setRole(OrganizationRole::GUEST)
            ->setInvitationExpiresAt($pending ? $expiresAt : null)
            ->setStatus($pending ? OrganizationMemberStatus::PENDING : OrganizationMemberStatus::ACTIVE);

        if (!$pending) {
            $membership->activate();
        }

        $organization->addMember($membership);
        $this->entityManager->persist($membership);

        return $membership;
    }

    /**
     * @deprecated Use inviteCompanyMember() or inviteExternalGuest() so seat attribution stays explicit.
     */
    public function invite(
        Organization $organization,
        User $user,
        User $invitedBy,
        OrganizationRole $role = OrganizationRole::MEMBER,
        bool $pending = false,
        ?\DateTimeImmutable $expiresAt = null,
    ): OrganizationMember {
        if ($role === OrganizationRole::GUEST) {
            return $this->inviteExternalGuest($organization, $user, $invitedBy, $pending, $expiresAt);
        }

        return $this->inviteCompanyMember($organization, $user, $invitedBy, $role, $pending, $expiresAt);
    }

    public function activatePendingMemberships(User $user): int
    {
        $activated = 0;
        foreach ($this->members->findPendingForUser($user) as $membership) {
            if ($membership->isInvitationExpired() || !$membership->getOrganization()?->isActive()) {
                continue;
            }

            $membership->activate();
            $activated++;
        }

        return $activated;
    }

    public function assertMembershipMayJoinTeam(OrganizationMember $membership, Team $team): void
    {
        if ($membership->getRole() !== OrganizationRole::GUEST) {
            return;
        }

        $organization = $membership->getOrganization();
        $user = $membership->getUser();
        if (!$organization instanceof Organization || !$user instanceof User) {
            throw new \DomainException('Cette invitation externe est invalide.');
        }

        foreach ($this->teamMembers->findBy(['user' => $user]) as $teamMember) {
            $existingTeam = $teamMember->getTeam();
            if (!$existingTeam instanceof Team || $existingTeam === $team) {
                continue;
            }

            $existingOrganization = $existingTeam->getOrganization();
            $sameOrganization = $existingOrganization === $organization
                || (
                    $existingOrganization?->getId() !== null
                    && $existingOrganization->getId() === $organization->getId()
                );
            if ($sameOrganization) {
                throw new \DomainException('Un invité externe peut accéder à un seul groupe de l’entreprise.');
            }
        }
    }

    public function removeMembership(OrganizationMember $membership): void
    {
        if ($membership->getRole() === OrganizationRole::OWNER) {
            throw new \DomainException('Le propriétaire de l’entreprise ne peut pas être supprimé.');
        }

        $organization = $membership->getOrganization();
        $user = $membership->getUser();
        if (!$organization instanceof Organization || !$user instanceof User) {
            return;
        }

        foreach ($this->teamMembers->findBy(['user' => $user]) as $teamMember) {
            if ($teamMember->getTeam()?->getOrganization()?->getId() === $organization->getId()) {
                $this->entityManager->remove($teamMember);
            }
        }

        $organization->removeMember($membership);
        $this->entityManager->remove($membership);
    }

    public function changeManagementRole(
        OrganizationMember $membership,
        User $actor,
        OrganizationRole $role,
    ): void {
        $organization = $membership->getOrganization();
        $owner = $organization?->getOwner();
        if (!$organization instanceof Organization || !(
            $owner === $actor
            || ($owner?->getId() !== null && $owner->getId() === $actor->getId())
        )) {
            throw new \DomainException('Seul le propriétaire peut déléguer la gestion de l’entreprise.');
        }
        if (!in_array($role, [OrganizationRole::ADMIN, OrganizationRole::MEMBER], true)) {
            throw new \InvalidArgumentException('Ce rôle ne peut pas être attribué depuis cette action.');
        }
        if (in_array($membership->getRole(), [OrganizationRole::OWNER, OrganizationRole::GUEST], true)) {
            throw new \DomainException('Le rôle de ce membre ne peut pas être modifié ici.');
        }

        $membership->setRole($role);
    }

    public function assertQuantityCanBeReducedTo(Organization $organization, int $quantity): void
    {
        $summary = $this->getSeatSummary($organization);
        if ($quantity < $summary['used']) {
            throw new \DomainException(sprintf(
                'Impossible de réduire à %d licence(s) : %d sont actuellement attribuées ou réservées.',
                $quantity,
                $summary['used'],
            ));
        }
    }
}
