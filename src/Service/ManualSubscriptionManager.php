<?php

namespace App\Service;

use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Entity\User;
use App\Entity\UserSubscription;
use App\Enum\OrganizationMemberStatus;
use App\Enum\OrganizationRole;
use App\Repository\OrganizationMemberRepository;
use App\Repository\OrganizationRepository;
use App\Repository\UserSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ManualSubscriptionManager
{
    public const ACTIVE_STATUS = 'admin_active';
    public const DISABLED_STATUS = 'admin_disabled';

    public function __construct(
        private readonly UserSubscriptionRepository $subscriptions,
        private readonly OrganizationRepository $organizations,
        private readonly OrganizationMemberRepository $members,
        private readonly OrganizationProvisioner $organizationProvisioner,
        private readonly OrganizationSeatManager $organizationSeats,
        private readonly StripePlanCatalog $stripePlans,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function activate(
        User $user,
        string $planCode,
        int $quantity = 1,
        ?string $organizationName = null,
    ): UserSubscription {
        $planCode = mb_strtolower(trim($planCode));
        if (!in_array($planCode, [SubscriptionPlanService::PLAN_PRO, SubscriptionPlanService::PLAN_TEAM], true)) {
            throw new \InvalidArgumentException('Sélectionnez une offre Pro ou Team valide.');
        }

        $subscription = $this->getOrCreateSubscription($user);
        $this->assertManualSubscription($subscription);

        if ($planCode === SubscriptionPlanService::PLAN_TEAM) {
            $quantity = $this->validateTeamQuantity($quantity);
        } else {
            $quantity = 1;
        }

        $subscription
            ->setPlanCode($planCode)
            ->setQuantity($quantity)
            ->setStatus(self::ACTIVE_STATUS)
            ->setCancelAtPeriodEnd(false)
            ->setCurrentPeriodEnd(null)
            ->setIsActive(true)
            ->touch();

        $organization = $this->organizationProvisioner->synchronize($user, $subscription);
        if ($organization instanceof Organization && $planCode === SubscriptionPlanService::PLAN_TEAM) {
            $name = trim((string) $organizationName);
            if ($name !== '') {
                $organization->setName($this->validateOrganizationName($name));
            }

            $summary = $this->organizationSeats->getSeatSummary($organization);
            if ($summary['used'] > $quantity) {
                throw new \DomainException(sprintf(
                    'Cette entreprise possède déjà %d licence(s) attribuée(s). Choisissez une quantité au moins équivalente.',
                    $summary['used'],
                ));
            }
        }

        $this->entityManager->flush();

        return $subscription;
    }

    public function deactivate(UserSubscription $subscription): void
    {
        $this->assertManualSubscription($subscription);
        $user = $subscription->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Le propriétaire de cet abonnement est introuvable.');
        }

        $subscription
            ->setIsActive(false)
            ->setStatus(self::DISABLED_STATUS)
            ->setCancelAtPeriodEnd(false)
            ->touch();
        $this->organizationProvisioner->synchronize($user, $subscription);
        $this->entityManager->flush();
    }

    public function updateOrganization(Organization $organization, string $name, int $quantity): void
    {
        $subscription = $this->requireManualTeamSubscription($organization);
        $quantity = $this->validateTeamQuantity($quantity);
        $this->organizationSeats->assertQuantityCanBeReducedTo($organization, $quantity);

        $organization->setName($this->validateOrganizationName($name));
        $subscription->setQuantity($quantity)->touch();
        $this->entityManager->flush();
    }

    public function setOrganizationActive(Organization $organization, bool $active): void
    {
        $subscription = $this->requireManualTeamSubscription($organization);
        $user = $subscription->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Le propriétaire de cet abonnement est introuvable.');
        }

        $subscription
            ->setIsActive($active)
            ->setStatus($active ? self::ACTIVE_STATUS : self::DISABLED_STATUS)
            ->setCancelAtPeriodEnd(false)
            ->touch();
        $this->organizationProvisioner->synchronize($user, $subscription);
        $this->entityManager->flush();
    }

    public function transferOrganization(Organization $organization, User $newOwner): void
    {
        $subscription = $this->requireManualTeamSubscription($organization);
        $previousOwner = $organization->getOwner();
        if (!$previousOwner instanceof User) {
            throw new \LogicException('Le propriétaire actuel est introuvable.');
        }
        if ($previousOwner === $newOwner || $previousOwner->getId() === $newOwner->getId()) {
            throw new \DomainException('Cet utilisateur est déjà propriétaire de l’entreprise.');
        }

        $ownedOrganization = $this->organizations->findOwnedBy($newOwner);
        if ($ownedOrganization instanceof Organization && $ownedOrganization->getId() !== $organization->getId()) {
            throw new \DomainException('Cet utilisateur possède déjà une autre entreprise.');
        }
        $newOwnerSubscription = $newOwner->getUserSubscription()
            ?? $this->subscriptions->findOneBy(['user' => $newOwner]);
        if ($newOwnerSubscription instanceof UserSubscription && $newOwnerSubscription !== $subscription) {
            throw new \DomainException('Cet utilisateur possède déjà un abonnement personnel.');
        }

        foreach ($this->members->findBy(['user' => $newOwner]) as $membership) {
            if (
                $membership->getOrganization()?->getId() !== $organization->getId()
                && $membership->getStatus() === OrganizationMemberStatus::ACTIVE
                && $membership->getRole() !== OrganizationRole::GUEST
            ) {
                throw new \DomainException('Cet utilisateur utilise déjà une licence dans une autre entreprise.');
            }
        }

        $newOwnerMembership = $this->members->findMembership($organization, $newOwner);
        $newOwnerAlreadyConsumesSeat = $newOwnerMembership?->consumesSeat() ?? false;
        if (!$newOwnerAlreadyConsumesSeat) {
            $seatSummary = $this->organizationSeats->getSeatSummary($organization);
            if ($seatSummary['used'] >= $seatSummary['purchased']) {
                throw new \DomainException('Aucune licence libre : augmentez d’abord la quantité avant le transfert.');
            }
        }
        if (!$newOwnerMembership instanceof OrganizationMember) {
            $newOwnerMembership = (new OrganizationMember())
                ->setUser($newOwner)
                ->setInvitedBy($previousOwner);
            $organization->addMember($newOwnerMembership);
            $this->entityManager->persist($newOwnerMembership);
        }
        $newOwnerMembership->setRole(OrganizationRole::OWNER)->activate();

        $previousOwnerMembership = $this->members->findMembership($organization, $previousOwner);
        if ($previousOwnerMembership instanceof OrganizationMember) {
            $previousOwnerMembership->setRole(OrganizationRole::ADMIN)->activate();
        }

        $previousOwner->setUserSubscription(null);
        $newOwner->setUserSubscription($subscription);
        $organization->setOwner($newOwner)->setSubscription($subscription);
        $subscription->touch();
        $this->entityManager->flush();
    }

    public function changeMemberRole(OrganizationMember $membership, OrganizationRole $role): void
    {
        $this->assertManageableMembership($membership);
        if (!in_array($role, [OrganizationRole::ADMIN, OrganizationRole::MEMBER], true)) {
            throw new \InvalidArgumentException('Le rôle doit être Administrateur ou Membre.');
        }

        $membership->setRole($role);
        $this->entityManager->flush();
    }

    public function toggleMembership(OrganizationMember $membership): void
    {
        $this->assertManageableMembership($membership);
        $organization = $membership->getOrganization();
        if (!$organization instanceof Organization) {
            throw new \LogicException('L’entreprise de ce membre est introuvable.');
        }

        if ($membership->getStatus() === OrganizationMemberStatus::ACTIVE) {
            $membership->setStatus(OrganizationMemberStatus::SUSPENDED);
        } else {
            if ($membership->getRole()->consumesSeat() && !$membership->consumesSeat()) {
                $summary = $this->organizationSeats->getSeatSummary($organization);
                if ($summary['used'] >= $summary['purchased']) {
                    throw new \DomainException('Aucune licence libre pour réactiver ce membre.');
                }
            }
            $membership->activate();
        }

        $this->entityManager->flush();
    }

    public function removeMembership(OrganizationMember $membership): void
    {
        $this->assertManageableMembership($membership);
        $this->organizationSeats->removeMembership($membership);
        $this->entityManager->flush();
    }

    public function isStripeManaged(UserSubscription $subscription): bool
    {
        return trim((string) $subscription->getStripeCustomerId()) !== ''
            || trim((string) $subscription->getStripeSubscriptionId()) !== ''
            || trim((string) $subscription->getStripePriceId()) !== '';
    }

    private function getOrCreateSubscription(User $user): UserSubscription
    {
        $subscription = $user->getUserSubscription() ?? $this->subscriptions->findOneBy(['user' => $user]);
        if ($subscription instanceof UserSubscription) {
            return $subscription;
        }

        $subscription = (new UserSubscription())->setUser($user);
        $user->setUserSubscription($subscription);
        $this->entityManager->persist($subscription);

        return $subscription;
    }

    private function requireManualTeamSubscription(Organization $organization): UserSubscription
    {
        $subscription = $organization->getSubscription();
        if (!$subscription instanceof UserSubscription) {
            throw new \LogicException('Aucun abonnement n’est associé à cette entreprise.');
        }
        $this->assertManualSubscription($subscription);
        if (mb_strtolower((string) $subscription->getPlanCode()) !== SubscriptionPlanService::PLAN_TEAM) {
            throw new \LogicException('Cette entreprise n’est pas associée à une offre Team.');
        }

        return $subscription;
    }

    private function assertManualSubscription(UserSubscription $subscription): void
    {
        if ($this->isStripeManaged($subscription)) {
            throw new \DomainException('Cet abonnement est géré par Stripe et ne peut pas être modifié manuellement.');
        }
    }

    private function assertManageableMembership(OrganizationMember $membership): void
    {
        $organization = $membership->getOrganization();
        if (!$organization instanceof Organization) {
            throw new \LogicException('L’entreprise de ce membre est introuvable.');
        }
        $this->requireManualTeamSubscription($organization);
        if ($membership->getRole() === OrganizationRole::OWNER) {
            throw new \DomainException('Transférez d’abord la propriété avant de modifier ce membre.');
        }
    }

    private function validateTeamQuantity(int $quantity): int
    {
        $minimum = $this->stripePlans->getTeamMinimumSeats();
        $maximum = $this->stripePlans->getTeamMaximumSeats();
        if ($quantity < $minimum || $quantity > $maximum) {
            throw new \InvalidArgumentException(sprintf(
                'La quantité doit être comprise entre %d et %d licences.',
                $minimum,
                $maximum,
            ));
        }

        return $quantity;
    }

    private function validateOrganizationName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 180) {
            throw new \InvalidArgumentException('Le nom de l’entreprise doit contenir entre 1 et 180 caractères.');
        }

        return $name;
    }
}
