<?php

namespace App\Tests\Service;

use App\Entity\Credential;
use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Entity\Team;
use App\Entity\TeamCredentialPermission;
use App\Entity\User;
use App\Entity\UserSubscription;
use App\Enum\OrganizationRole;
use App\Enum\TeamRole;
use App\Repository\OrganizationMemberRepository;
use App\Repository\OrganizationRepository;
use App\Repository\TeamCredentialPermissionRepository;
use App\Repository\TeamMemberRepository;
use App\Repository\UserRepository;
use App\Repository\SubscriptionPlanConfigurationRepository;
use App\Service\OrganizationSeatManager;
use App\Service\TeamCreationManager;
use App\Service\TeamCredentialPermissionManager;
use App\Service\TeamMemberAssignmentManager;
use App\Service\SubscriptionPlanService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class TeamCreationManagerTest extends TestCase
{
    /** @dataProvider passwordPermissions */
    public function testCreationAddsMembersAndKeepsTheSelectedPasswordPermission(bool $canReveal): void
    {
        $owner = (new User())->setEmail('owner@example.com');
        $member = (new User())->setEmail('member@example.com');
        $organization = (new Organization())->setOwner($owner)->setSubscription(
            (new UserSubscription())->setPlanCode('team')->setStatus('active')->setIsActive(true)->setQuantity(6),
        );
        $team = (new Team())->setName('Marketing')->addCredential((new Credential())->setName('Example'));
        $connection = $this->connection();
        $connection->expects(self::once())->method('commit');
        $connection->expects(self::never())->method('rollBack');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $persisted = [];
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void { $persisted[] = $entity; });
        $users = $this->createMock(UserRepository::class);
        $users->method('findOneBy')->willReturnCallback(static fn (array $criteria): ?User => $criteria['email'] === 'member@example.com' ? $member : null);

        $assignments = $this->manager($em, $users)->create($team, $owner, $organization, [
            ['email' => 'member@example.com', 'role' => TeamRole::ADMIN],
            ['email' => 'new@example.com', 'role' => TeamRole::MEMBER],
        ], $canReveal);

        self::assertSame($owner, $team->getOwner());
        self::assertCount(3, $team->getMembers());
        self::assertSame(TeamRole::OWNER, $team->getMembers()->first()->getRole());
        self::assertCount(2, $assignments);
        self::assertNull($assignments[0]['invitationExpiresAt']);
        self::assertSame(TeamRole::ADMIN, $assignments[0]['member']->getRole());
        self::assertInstanceOf(\DateTimeImmutable::class, $assignments[1]['invitationExpiresAt']);
        self::assertContains('ROLE_GUEST', $assignments[1]['user']->getRoles());
        $permissions = array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof TeamCredentialPermission));
        self::assertCount(1, $permissions);
        self::assertSame($canReveal, $permissions[0]->canRevealPassword());
    }

    public static function passwordPermissions(): array
    {
        return ['connection only' => [false], 'password visible' => [true]];
    }

    public function testCompanyInvitationsAutomaticallyAssignALicenseAndPreserveTheGroupRole(): void
    {
        $owner = (new User())->setEmail('owner@example.com');
        $guest = (new User())->setEmail('guest@example.com');
        $subscription = (new UserSubscription())->setPlanCode('team')->setStatus('active')->setIsActive(true);
        $organization = (new Organization())->setOwner($owner)->setSubscription($subscription);
        $connection = $this->connection();
        $connection->expects(self::once())->method('commit');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $users = $this->createMock(UserRepository::class);
        $users->method('findOneBy')->willReturn($guest);

        $assignments = $this->manager($em, $users)->create((new Team())->setName('Agency'), $owner, $organization, [
            ['email' => 'guest@example.com', 'role' => TeamRole::ADMIN],
        ], true);

        self::assertSame(TeamRole::ADMIN, $assignments[0]['member']->getRole());
        self::assertCount(1, $organization->getMembers());
        self::assertSame(OrganizationRole::MEMBER, $organization->getMembers()->first()->getRole());
        self::assertTrue($organization->getMembers()->first()->consumesSeat());
    }

    public function testAnInvalidMemberRollsBackTheEntireCreation(): void
    {
        $owner = (new User())->setEmail('owner@example.com');
        $employee = (new User())->setEmail('employee@example.com');
        $subscription = (new UserSubscription())->setPlanCode('team')->setStatus('active')->setIsActive(true);
        $organization = (new Organization())->setOwner($owner)->setSubscription($subscription);
        $organization->addMember((new OrganizationMember())->setUser($employee)->setRole(OrganizationRole::MEMBER)->activate());
        $connection = $this->connection();
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('commit');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->expects(self::exactly(2))->method('flush');
        $users = $this->createMock(UserRepository::class);
        $users->method('findOneBy')->willReturnCallback(static fn (array $criteria): ?User => $criteria['email'] === 'employee@example.com' ? $employee : null);

        $this->expectException(\InvalidArgumentException::class);
        $this->manager($em, $users)->create((new Team())->setName('Company'), $owner, $organization, [
            ['email' => 'employee@example.com', 'role' => TeamRole::MEMBER],
            ['email' => 'unknown@example.com', 'role' => TeamRole::OWNER],
        ], false);
    }

    /** @dataProvider duplicateMembers */
    public function testDuplicateMembersAreRejectedBeforeCreatingAnything(array $members): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getConnection');
        $em->expects(self::never())->method('persist');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('teams.wizard.duplicate_email');
        $this->manager($em, $this->createMock(UserRepository::class))->create(
            new Team(), (new User())->setEmail('owner@example.com'), null, $members, false,
        );
    }

    public static function duplicateMembers(): array
    {
        return [
            'automatic owner' => [[['email' => ' OWNER@example.com ', 'role' => TeamRole::MEMBER]]],
            'case-insensitive duplicates' => [[
                ['email' => 'member@example.com', 'role' => TeamRole::MEMBER],
                ['email' => ' MEMBER@example.com ', 'role' => TeamRole::MEMBER],
            ]],
        ];
    }

    private function connection(): Connection
    {
        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->onlyMethods(['beginTransaction', 'commit', 'rollBack'])->getMock();
        $connection->expects(self::once())->method('beginTransaction');

        return $connection;
    }

    private function manager(EntityManagerInterface $em, UserRepository $users): TeamCreationManager
    {
        $teamMembers = $this->createMock(TeamMemberRepository::class);
        $seats = new OrganizationSeatManager($this->createMock(OrganizationRepository::class), $this->createMock(OrganizationMemberRepository::class), $teamMembers, $em);

        return new TeamCreationManager(
            $em,
            new TeamMemberAssignmentManager($users, $teamMembers, $seats, $em, new SubscriptionPlanService(
                $this->createMock(SubscriptionPlanConfigurationRepository::class), $em, $this->createMock(OrganizationMemberRepository::class),
            )),
            new TeamCredentialPermissionManager($this->createMock(TeamCredentialPermissionRepository::class), $em),
            $seats,
        );
    }
}
