<?php

namespace App\Tests\Service;

use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\User;
use App\Entity\UserSubscription;
use App\Enum\OrganizationRole;
use App\Enum\OrganizationStatus;
use App\Repository\OrganizationMemberRepository;
use App\Repository\OrganizationRepository;
use App\Repository\TeamRepository;
use App\Service\OrganizationProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class OrganizationProvisionerTest extends TestCase
{
    public function testActiveTeamSubscriptionCreatesWorkspaceOwnerAndAttachesExistingGroups(): void
    {
        $user = (new User())
            ->setEmail('owner@example.test')
            ->setCompany('Acme')
            ->setPassword('hashed');
        $subscription = (new UserSubscription())
            ->setUser($user)
            ->setPlanCode('team')
            ->setIsActive(true)
            ->setQuantity(6);
        $user->setUserSubscription($subscription);
        $team = (new Team())->setName('Marketing')->setOwner($user);

        $organizations = $this->createMock(OrganizationRepository::class);
        $organizations->method('findOwnedBy')->with($user)->willReturn(null);
        $members = $this->createMock(OrganizationMemberRepository::class);
        $members->method('findMembership')->willReturn(null);
        $teams = $this->createMock(TeamRepository::class);
        $teams->method('findBy')->with(['owner' => $user, 'organization' => null])->willReturn([$team]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $persisted = [];
        $entityManager->expects(self::exactly(2))->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            },
        );

        $organization = (new OrganizationProvisioner($organizations, $members, $teams, $entityManager))
            ->synchronize($user, $subscription);

        self::assertInstanceOf(Organization::class, $organization);
        self::assertSame('Acme', $organization->getName());
        self::assertSame(OrganizationStatus::ACTIVE, $organization->getStatus());
        self::assertSame($organization, $subscription->getOrganization());
        self::assertSame($organization, $team->getOrganization());
        self::assertCount(1, $organization->getMembers());
        self::assertSame(OrganizationRole::OWNER, $organization->getMembers()->first()->getRole());
        self::assertTrue((bool) array_filter($persisted, static fn (object $entity): bool => $entity instanceof OrganizationMember));
    }

    public function testCanceledTeamSubscriptionSuspendsExistingWorkspaceWithoutDeletingIt(): void
    {
        $user = (new User())->setEmail('owner@example.test')->setCompany('Acme')->setPassword('hashed');
        $subscription = (new UserSubscription())
            ->setUser($user)
            ->setPlanCode('team')
            ->setIsActive(false);
        $organization = (new Organization())->setName('Acme')->setOwner($user)->setSubscription($subscription);

        $provisioner = new OrganizationProvisioner(
            $this->createMock(OrganizationRepository::class),
            $this->createMock(OrganizationMemberRepository::class),
            $this->createMock(TeamRepository::class),
            $this->createMock(EntityManagerInterface::class),
        );

        self::assertSame($organization, $provisioner->synchronize($user, $subscription));
        self::assertSame(OrganizationStatus::SUSPENDED, $organization->getStatus());
    }

    public function testExistingGroupMembersAreImportedIntoTheCompanyOnlyOnce(): void
    {
        $owner = (new User())->setEmail('owner@example.test')->setCompany('Acme')->setPassword('hashed');
        $employee = (new User())->setEmail('employee@example.test')->setPassword('hashed');
        $subscription = (new UserSubscription())
            ->setUser($owner)
            ->setPlanCode('team')
            ->setIsActive(true)
            ->setQuantity(6);
        $owner->setUserSubscription($subscription);

        $firstTeam = (new Team())->setName('Marketing')->setOwner($owner);
        $firstTeam->addMember((new TeamMember())->setUser($employee));
        $secondTeam = (new Team())->setName('Sales')->setOwner($owner);
        $secondTeam->addMember((new TeamMember())->setUser($employee));

        $organizations = $this->createMock(OrganizationRepository::class);
        $organizations->method('findOwnedBy')->willReturn(null);
        $members = $this->createMock(OrganizationMemberRepository::class);
        $members->method('findMembership')->willReturn(null);
        $teams = $this->createMock(TeamRepository::class);
        $teams->method('findBy')->willReturn([$firstTeam, $secondTeam]);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $organization = (new OrganizationProvisioner($organizations, $members, $teams, $entityManager))
            ->synchronize($owner, $subscription);

        self::assertCount(2, $organization?->getMembers());
        self::assertSame($organization, $firstTeam->getOrganization());
        self::assertSame($organization, $secondTeam->getOrganization());
        self::assertSame(OrganizationRole::MEMBER, $organization?->getMembers()->last()->getRole());
    }
}
