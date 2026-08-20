<?php

namespace App\Tests\Service;

use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Entity\User;
use App\Entity\UserSubscription;
use App\Enum\OrganizationMemberStatus;
use App\Enum\OrganizationRole;
use App\Repository\OrganizationMemberRepository;
use App\Repository\OrganizationRepository;
use App\Repository\TeamMemberRepository;
use App\Repository\UserRepository;
use App\Service\MailerService;
use App\Service\OrganizationInvitationManager;
use App\Service\OrganizationSeatManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class OrganizationInvitationManagerTest extends TestCase
{
    public function testExistingAccountIsActivatedImmediatelyAndUsesOneSeat(): void
    {
        [$organization, $owner] = $this->createActiveOrganization();
        $employee = (new User())->setEmail('employee@example.test')->setPassword('hashed');
        $users = $this->createMock(UserRepository::class);
        $users->method('findOneBy')->willReturn($employee);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(OrganizationMember::class));
        $entityManager->expects(self::once())->method('flush');
        $mailer = $this->createMock(MailerService::class);
        $mailer->expects(self::once())->method('send');

        [$manager, $seats] = $this->createManager($users, $entityManager, $mailer);
        $result = $manager->inviteEmployee($organization, 'EMPLOYEE@example.test', OrganizationRole::MEMBER, $owner);

        self::assertFalse($result['pending']);
        self::assertSame(OrganizationMemberStatus::ACTIVE, $result['membership']->getStatus());
        self::assertSame(2, $seats->getSeatSummary($organization)['used']);
    }

    public function testUnknownAccountReservesSeatUntilRegistration(): void
    {
        [$organization, $owner] = $this->createActiveOrganization();
        $users = $this->createMock(UserRepository::class);
        $users->method('findOneBy')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $persisted = [];
        $entityManager->expects(self::exactly(2))->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            },
        );
        $entityManager->expects(self::once())->method('flush');
        $mailer = $this->createMock(MailerService::class);
        $mailer->expects(self::once())->method('send');

        [$manager, $seats] = $this->createManager($users, $entityManager, $mailer);
        $result = $manager->inviteEmployee($organization, 'new@example.test', OrganizationRole::MEMBER, $owner);

        self::assertTrue($result['pending']);
        self::assertSame(OrganizationMemberStatus::PENDING, $result['membership']->getStatus());
        self::assertNotNull($result['membership']->getInvitationExpiresAt());
        self::assertSame(2, $seats->getSeatSummary($organization)['used']);
        self::assertCount(2, $persisted);
    }

    /** @return array{OrganizationInvitationManager, OrganizationSeatManager} */
    private function createManager(
        UserRepository $users,
        EntityManagerInterface $entityManager,
        MailerService $mailer,
    ): array {
        $seats = new OrganizationSeatManager(
            $this->createMock(OrganizationRepository::class),
            $this->createMock(OrganizationMemberRepository::class),
            $this->createMock(TeamMemberRepository::class),
            $entityManager,
        );
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://key-nest.example/action');
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Invitation MYKEYNEST');

        return [
            new OrganizationInvitationManager(
                $users,
                $seats,
                $entityManager,
                $mailer,
                $urlGenerator,
                $translator,
                $this->createMock(LoggerInterface::class),
            ),
            $seats,
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
        $organization->addMember(
            (new OrganizationMember())
                ->setOrganization($organization)
                ->setUser($owner)
                ->setRole(OrganizationRole::OWNER)
                ->setStatus(OrganizationMemberStatus::ACTIVE)
                ->activate(),
        );

        return [$organization, $owner];
    }
}
