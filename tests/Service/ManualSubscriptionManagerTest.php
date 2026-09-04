<?php

namespace App\Tests\Service;

use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Entity\User;
use App\Entity\UserSubscription;
use App\Enum\OrganizationRole;
use App\Repository\OrganizationMemberRepository;
use App\Repository\OrganizationRepository;
use App\Repository\TeamMemberRepository;
use App\Repository\TeamRepository;
use App\Repository\UserSubscriptionRepository;
use App\Service\ManualSubscriptionManager;
use App\Service\OrganizationProvisioner;
use App\Service\OrganizationSeatManager;
use App\Service\StripePlanCatalog;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ManualSubscriptionManagerTest extends TestCase
{
    public function testItActivatesTeamAndProvisionsTheCompanyWithoutStripe(): void
    {
        $user = (new User())->setEmail('owner@example.com')->setCompany('Example');
        $subscriptions = $this->createMock(UserSubscriptionRepository::class);
        $subscriptions->method('findOneBy')->willReturn(null);
        $organizations = $this->createMock(OrganizationRepository::class);
        $organizations->method('findOwnedBy')->willReturn(null);
        $members = $this->createMock(OrganizationMemberRepository::class);
        $members->method('findMembership')->willReturn(null);
        $teams = $this->createMock(TeamRepository::class);
        $teams->method('findBy')->willReturn([]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(3))->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $manager = $this->createManager($subscriptions, $organizations, $members, $teams, $entityManager);
        $subscription = $manager->activate($user, 'team', 8, 'Example Group');

        self::assertTrue($subscription->isActive());
        self::assertSame('team', $subscription->getPlanCode());
        self::assertSame(8, $subscription->getQuantity());
        self::assertNull($subscription->getStripeSubscriptionId());
        self::assertSame('Example Group', $subscription->getOrganization()?->getName());
        self::assertTrue($subscription->getOrganization()?->isActive());
        self::assertSame(OrganizationRole::OWNER, $subscription->getOrganization()?->getMembers()->first()?->getRole());
    }

    public function testItRefusesToOverwriteAStripeSubscription(): void
    {
        $user = (new User())->setEmail('stripe@example.com')->setCompany('Stripe');
        $subscription = (new UserSubscription())
            ->setUser($user)
            ->setStripeSubscriptionId('sub_123')
            ->setPlanCode('pro')
            ->setIsActive(true);
        $user->setUserSubscription($subscription);

        $manager = $this->createManager(
            $this->createMock(UserSubscriptionRepository::class),
            $this->createMock(OrganizationRepository::class),
            $this->createMock(OrganizationMemberRepository::class),
            $this->createMock(TeamRepository::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('géré par Stripe');
        $manager->activate($user, 'team', 6);
    }

    public function testItTransfersAManualCompanyAndKeepsThePreviousOwnerAsAdministrator(): void
    {
        $previousOwner = (new User())->setEmail('old@example.com')->setCompany('Example');
        $newOwner = (new User())->setEmail('new@example.com')->setCompany('Example');
        $subscription = (new UserSubscription())
            ->setUser($previousOwner)
            ->setPlanCode('team')
            ->setQuantity(6)
            ->setStatus(ManualSubscriptionManager::ACTIVE_STATUS)
            ->setIsActive(true);
        $previousOwner->setUserSubscription($subscription);
        $organization = (new Organization())
            ->setName('Example')
            ->setOwner($previousOwner)
            ->setSubscription($subscription);
        $previousMembership = (new OrganizationMember())->setUser($previousOwner)->setRole(OrganizationRole::OWNER)->activate();
        $newMembership = (new OrganizationMember())->setUser($newOwner)->setRole(OrganizationRole::MEMBER)->activate();
        $organization->addMember($previousMembership)->addMember($newMembership);

        $subscriptions = $this->createMock(UserSubscriptionRepository::class);
        $subscriptions->method('findOneBy')->willReturn(null);
        $organizations = $this->createMock(OrganizationRepository::class);
        $organizations->method('findOwnedBy')->with($newOwner)->willReturn(null);
        $members = $this->createMock(OrganizationMemberRepository::class);
        $members->method('findBy')->with(['user' => $newOwner])->willReturn([$newMembership]);
        $members->method('findMembership')->willReturnCallback(
            static fn (Organization $company, User $user): ?OrganizationMember => $user === $newOwner ? $newMembership : $previousMembership,
        );
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $manager = $this->createManager(
            $subscriptions,
            $organizations,
            $members,
            $this->createMock(TeamRepository::class),
            $entityManager,
        );
        $manager->transferOrganization($organization, $newOwner);

        self::assertSame($newOwner, $organization->getOwner());
        self::assertSame($newOwner, $subscription->getUser());
        self::assertNull($previousOwner->getUserSubscription());
        self::assertSame($subscription, $newOwner->getUserSubscription());
        self::assertSame(OrganizationRole::ADMIN, $previousMembership->getRole());
        self::assertSame(OrganizationRole::OWNER, $newMembership->getRole());
    }

    private function createManager(
        UserSubscriptionRepository $subscriptions,
        OrganizationRepository $organizations,
        OrganizationMemberRepository $members,
        TeamRepository $teams,
        EntityManagerInterface $entityManager,
    ): ManualSubscriptionManager {
        $seatManager = new OrganizationSeatManager(
            $organizations,
            $members,
            $this->createMock(TeamMemberRepository::class),
            $entityManager,
        );
        $provisioner = new OrganizationProvisioner(
            $organizations,
            $members,
            $teams,
            $entityManager,
        );

        return new ManualSubscriptionManager(
            $subscriptions,
            $organizations,
            $members,
            $provisioner,
            $seatManager,
            new StripePlanCatalog(6, 250),
            $entityManager,
        );
    }
}
