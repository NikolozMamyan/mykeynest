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
use App\Repository\OrganizationMemberRepository;
use App\Repository\OrganizationRepository;
use App\Repository\TeamMemberRepository;
use App\Service\OrganizationSeatManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class OrganizationSeatManagerTest extends TestCase
{
    public function testSeatSummaryCountsPeopleAndPendingInvitationsButNotGuests(): void
    {
        [$organization, $owner] = $this->createActiveOrganization(6);
        $organization->addMember($this->membership($organization, $owner, OrganizationRole::OWNER, active: true));
        $organization->addMember($this->membership($organization, new User(), OrganizationRole::ADMIN, active: true));
        $organization->addMember($this->membership($organization, new User(), OrganizationRole::MEMBER, active: true));
        $organization->addMember($this->membership($organization, new User(), OrganizationRole::MEMBER, active: false));
        $organization->addMember($this->membership($organization, new User(), OrganizationRole::GUEST, active: true));

        $summary = $this->createManager()->getSeatSummary($organization);

        self::assertSame(6, $summary['purchased']);
        self::assertSame(4, $summary['used']);
        self::assertSame(3, $summary['active']);
        self::assertSame(1, $summary['pending']);
        self::assertSame(2, $summary['available']);
        self::assertSame(1, $summary['guests']);
    }

    public function testInvitationIsRejectedWhenEveryPurchasedSeatIsReserved(): void
    {
        [$organization, $owner] = $this->createActiveOrganization(2);
        $organization->addMember($this->membership($organization, $owner, OrganizationRole::OWNER, active: true));
        $organization->addMember($this->membership($organization, new User(), OrganizationRole::MEMBER, active: false));

        $members = $this->createMock(OrganizationMemberRepository::class);
        $members->method('findMembership')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $manager = $this->createManager($members, $entityManager);

        $this->expectException(\DomainException::class);
        $manager->invite($organization, new User(), $owner);
    }

    public function testExistingCompanyMemberCanJoinAnotherGroupWithoutUsingAnotherSeat(): void
    {
        [$organization, $owner] = $this->createActiveOrganization(2);
        $memberUser = new User();
        $membership = $this->membership($organization, $memberUser, OrganizationRole::MEMBER, active: true);
        $organization->addMember($this->membership($organization, $owner, OrganizationRole::OWNER, active: true));
        $organization->addMember($membership);

        $members = $this->createMock(OrganizationMemberRepository::class);
        $members->method('findMembership')->with($organization, $memberUser)->willReturn($membership);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $manager = $this->createManager($members, $entityManager);

        self::assertSame($membership, $manager->invite($organization, $memberUser, $owner));
        self::assertSame(2, $manager->getSeatSummary($organization)['used']);
    }

    public function testExternalGuestCannotJoinASecondGroupInTheSameOrganization(): void
    {
        [$organization, $owner] = $this->createActiveOrganization(6);
        $guest = (new User())->setEmail('external@example.test');
        $membership = $this->membership($organization, $guest, OrganizationRole::GUEST, active: true);
        $firstTeam = (new Team())->setName('Agency A')->setOwner($owner)->setOrganization($organization);
        $secondTeam = (new Team())->setName('Agency B')->setOwner($owner)->setOrganization($organization);
        $teamMember = (new TeamMember())->setTeam($firstTeam)->setUser($guest);

        $teamMembers = $this->createMock(TeamMemberRepository::class);
        $teamMembers->method('findBy')->with(['user' => $guest])->willReturn([$teamMember]);
        $manager = $this->createManager(teamMembers: $teamMembers);

        $this->expectException(\DomainException::class);
        $manager->assertMembershipMayJoinTeam($membership, $secondTeam);
    }

    public function testOwnerCanDelegateCompanyAdministrationToAnEmployee(): void
    {
        [$organization, $owner] = $this->createActiveOrganization(6);
        $membership = $this->membership($organization, new User(), OrganizationRole::MEMBER, active: true);
        $organization->addMember($membership);
        $members = $this->createMock(OrganizationMemberRepository::class);
        $members->method('findMembership')->with($organization, $membership->getUser())->willReturn($membership);
        $manager = $this->createManager($members);

        $manager->changeManagementRole($membership, $owner, OrganizationRole::ADMIN);

        self::assertSame(OrganizationRole::ADMIN, $membership->getRole());
        self::assertTrue($manager->canManage($organization, $membership->getUser()));
    }

    public function testAssigningAnExistingAdminToAnotherGroupDoesNotDowngradeCompanyRole(): void
    {
        [$organization, $owner] = $this->createActiveOrganization(6);
        $admin = new User();
        $membership = $this->membership($organization, $admin, OrganizationRole::ADMIN, active: true);
        $organization->addMember($membership);
        $members = $this->createMock(OrganizationMemberRepository::class);
        $members->method('findMembership')->with($organization, $admin)->willReturn($membership);
        $manager = $this->createManager($members);

        self::assertSame($membership, $manager->invite($organization, $admin, $owner, OrganizationRole::MEMBER));
        self::assertSame(OrganizationRole::ADMIN, $membership->getRole());
    }

    private function createManager(
        ?OrganizationMemberRepository $members = null,
        ?EntityManagerInterface $entityManager = null,
        ?TeamMemberRepository $teamMembers = null,
    ): OrganizationSeatManager {
        return new OrganizationSeatManager(
            $this->createMock(OrganizationRepository::class),
            $members ?? $this->createMock(OrganizationMemberRepository::class),
            $teamMembers ?? $this->createMock(TeamMemberRepository::class),
            $entityManager ?? $this->createMock(EntityManagerInterface::class),
        );
    }

    /** @return array{Organization, User} */
    private function createActiveOrganization(int $quantity): array
    {
        $owner = (new User())->setEmail('owner@example.test')->setCompany('Acme')->setPassword('hashed');
        $subscription = (new UserSubscription())
            ->setUser($owner)
            ->setPlanCode('team')
            ->setStatus('active')
            ->setIsActive(true)
            ->setQuantity($quantity);
        $owner->setUserSubscription($subscription);
        $organization = (new Organization())->setName('Acme')->setOwner($owner)->setSubscription($subscription);

        return [$organization, $owner];
    }

    private function membership(
        Organization $organization,
        User $user,
        OrganizationRole $role,
        bool $active,
    ): OrganizationMember {
        $membership = (new OrganizationMember())
            ->setOrganization($organization)
            ->setUser($user)
            ->setRole($role)
            ->setStatus($active ? OrganizationMemberStatus::ACTIVE : OrganizationMemberStatus::PENDING);

        return $active ? $membership->activate() : $membership->setInvitationExpiresAt(new \DateTimeImmutable('+1 day'));
    }
}
