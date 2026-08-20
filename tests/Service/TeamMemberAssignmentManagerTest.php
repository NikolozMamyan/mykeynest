<?php

namespace App\Tests\Service;

use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\User;
use App\Entity\UserSubscription;
use App\Enum\OrganizationMemberStatus;
use App\Enum\OrganizationRole;
use App\Enum\TeamRole;
use App\Repository\OrganizationMemberRepository;
use App\Repository\OrganizationRepository;
use App\Repository\TeamMemberRepository;
use App\Repository\UserRepository;
use App\Service\OrganizationSeatManager;
use App\Service\TeamMemberAssignmentManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class TeamMemberAssignmentManagerTest extends TestCase
{
    public function testExistingCompanyEmployeeJoinsGroupWithoutCreatingAnotherSeat(): void
    {
        [$organization, $owner] = $this->createActiveOrganization();
        $employee = (new User())->setEmail('employee@example.test')->setPassword('hashed');
        $membership = $this->membership($organization, $employee, OrganizationRole::MEMBER);
        $organization->addMember($membership);
        $memberCountBefore = $organization->getMembers()->count();
        $team = (new Team())->setName('Marketing')->setOwner($owner)->setOrganization($organization);

        $users = $this->createMock(UserRepository::class);
        $users->method('findOneBy')->with(['email' => 'employee@example.test'])->willReturn($employee);
        $organizationMembers = $this->createMock(OrganizationMemberRepository::class);
        $organizationMembers->method('findMembership')->with($organization, $employee)->willReturn($membership);
        $teamMembers = $this->createMock(TeamMemberRepository::class);
        $teamMembers->method('findOneBy')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(TeamMember::class));
        $entityManager->expects(self::once())->method('flush');

        [$manager] = $this->createAssignmentManager($users, $organizationMembers, $teamMembers, $entityManager);
        $result = $manager->assign($team, ' EMPLOYEE@example.test ', TeamRole::MEMBER, $owner);

        self::assertSame($employee, $result['user']);
        self::assertSame(TeamRole::MEMBER, $result['member']->getRole());
        self::assertNull($result['invitationExpiresAt']);
        self::assertCount($memberCountBefore, $organization->getMembers());
    }

    public function testUnknownEmployeeMustFirstBeInvitedFromCompanyWorkspace(): void
    {
        [$organization, $owner] = $this->createActiveOrganization();
        $team = (new Team())->setName('Marketing')->setOwner($owner)->setOrganization($organization);
        $users = $this->createMock(UserRepository::class);
        $users->method('findOneBy')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        [$manager] = $this->createAssignmentManager(
            $users,
            $this->createMock(OrganizationMemberRepository::class),
            $this->createMock(TeamMemberRepository::class),
            $entityManager,
        );

        $this->expectException(\DomainException::class);
        $manager->assign($team, 'unknown@example.test', TeamRole::MEMBER, $owner);
    }

    public function testExternalGuestJoinsOneGroupWithoutConsumingASeat(): void
    {
        [$organization, $owner] = $this->createActiveOrganization();
        $guest = (new User())->setEmail('external@example.test')->setPassword('hashed');
        $team = (new Team())->setName('Agency')->setOwner($owner)->setOrganization($organization);

        $users = $this->createMock(UserRepository::class);
        $users->method('findOneBy')->willReturn($guest);
        $organizationMembers = $this->createMock(OrganizationMemberRepository::class);
        $organizationMembers->method('findMembership')->with($organization, $guest)->willReturn(null);
        $teamMembers = $this->createMock(TeamMemberRepository::class);
        $teamMembers->method('findBy')->with(['user' => $guest])->willReturn([]);
        $teamMembers->method('findOneBy')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $persisted = [];
        $entityManager->expects(self::exactly(2))->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            },
        );
        $entityManager->expects(self::once())->method('flush');

        [$manager, $organizationSeats] = $this->createAssignmentManager($users, $organizationMembers, $teamMembers, $entityManager);
        $result = $manager->assign($team, 'external@example.test', TeamRole::ADMIN, $owner, true);

        self::assertSame(TeamRole::MEMBER, $result['member']->getRole());
        self::assertSame(1, $organizationSeats->getSeatSummary($organization)['used']);
        self::assertSame(OrganizationRole::GUEST, $organization->getMembers()->last()->getRole());
        self::assertCount(2, $persisted);
    }

    public function testRemovingEmployeeFromGroupKeepsCompanySeat(): void
    {
        [$organization, $owner] = $this->createActiveOrganization();
        $employee = (new User())->setEmail('employee@example.test');
        $membership = $this->membership($organization, $employee, OrganizationRole::MEMBER);
        $organization->addMember($membership);
        $team = (new Team())->setName('Marketing')->setOwner($owner)->setOrganization($organization);
        $teamMember = (new TeamMember())->setTeam($team)->setUser($employee);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('remove')->with($teamMember);

        [$manager] = $this->createAssignmentManager(
            $this->createMock(UserRepository::class),
            $this->createMock(OrganizationMemberRepository::class),
            $this->createMock(TeamMemberRepository::class),
            $entityManager,
        );
        $manager->removeAssignment($teamMember);

        self::assertTrue($organization->getMembers()->contains($membership));
    }

    public function testRemovingExternalGuestFromTheirGroupRemovesGuestMembership(): void
    {
        [$organization, $owner] = $this->createActiveOrganization();
        $guest = (new User())->setEmail('external@example.test');
        $membership = $this->membership($organization, $guest, OrganizationRole::GUEST);
        $organization->addMember($membership);
        $team = (new Team())->setName('Agency')->setOwner($owner)->setOrganization($organization);
        $teamMember = (new TeamMember())->setTeam($team)->setUser($guest);
        $teamMembers = $this->createMock(TeamMemberRepository::class);
        $teamMembers->method('findBy')->with(['user' => $guest])->willReturn([$teamMember]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $removed = [];
        $entityManager->expects(self::exactly(2))->method('remove')->willReturnCallback(
            static function (object $entity) use (&$removed): void {
                $removed[] = $entity;
            },
        );

        [$manager] = $this->createAssignmentManager(
            $this->createMock(UserRepository::class),
            $this->createMock(OrganizationMemberRepository::class),
            $teamMembers,
            $entityManager,
        );
        $manager->removeAssignment($teamMember);

        self::assertFalse($organization->getMembers()->contains($membership));
        self::assertContains($teamMember, $removed, true);
        self::assertContains($membership, $removed, true);
    }

    /** @return array{TeamMemberAssignmentManager, OrganizationSeatManager} */
    private function createAssignmentManager(
        UserRepository $users,
        OrganizationMemberRepository $organizationMembers,
        TeamMemberRepository $teamMembers,
        EntityManagerInterface $entityManager,
    ): array {
        $organizationSeats = new OrganizationSeatManager(
            $this->createMock(OrganizationRepository::class),
            $organizationMembers,
            $teamMembers,
            $entityManager,
        );

        return [
            new TeamMemberAssignmentManager($users, $teamMembers, $organizationSeats, $entityManager),
            $organizationSeats,
        ];
    }

    /** @return array{Organization, User} */
    private function createActiveOrganization(): array
    {
        $owner = (new User())->setEmail('owner@example.test')->setCompany('Acme')->setPassword('hashed');
        $subscription = (new UserSubscription())
            ->setUser($owner)
            ->setPlanCode('team')
            ->setStatus('active')
            ->setIsActive(true)
            ->setQuantity(6);
        $owner->setUserSubscription($subscription);
        $organization = (new Organization())->setName('Acme')->setOwner($owner)->setSubscription($subscription);
        $organization->addMember($this->membership($organization, $owner, OrganizationRole::OWNER));

        return [$organization, $owner];
    }

    private function membership(Organization $organization, User $user, OrganizationRole $role): OrganizationMember
    {
        return (new OrganizationMember())
            ->setOrganization($organization)
            ->setUser($user)
            ->setRole($role)
            ->setStatus(OrganizationMemberStatus::ACTIVE)
            ->activate();
    }
}
