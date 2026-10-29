<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Repository\OrganizationMemberRepository;
use App\Repository\SubscriptionPlanConfigurationRepository;
use App\Repository\UserRepository;
use App\Service\AdminNotificationService;
use App\Service\ExtensionOnboardingPolicy;
use App\Service\GoogleAccountService;
use App\Service\MailerService;
use App\Service\NotificationService;
use App\Service\SubscriptionPlanService;
use App\Service\UserRegistrationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class GoogleAccountServiceTest extends TestCase
{
    public function testExistingGoogleSubjectIsUsedEvenAfterEmailChange(): void
    {
        [$service, $users] = $this->service();
        $user = (new User())->setEmail('old@example.com')->setGoogleSubject('subject');
        $users->expects($this->once())->method('findOneBy')->with(['googleSubject' => 'subject'])->willReturn($user);
        self::assertSame([$user, false], $service->resolve(['sub' => 'subject', 'email' => 'new@example.com'], Request::create('/'), null));
    }

    public function testExistingEmailIsNeverLinkedWithoutAuthenticatedConsent(): void
    {
        [$service, $users, $em] = $this->service();
        $users->method('findOneBy')->willReturnCallback(static fn (array $criteria) => isset($criteria['email']) ? new User() : null);
        $em->expects($this->never())->method('persist');
        self::expectExceptionMessage('auth.google.link_required');
        $service->resolve(['sub' => 'subject', 'email' => 'existing@example.com'], Request::create('/'), null);
    }

    public function testAuthenticatedUserCanLinkMatchingGoogleEmail(): void
    {
        [$service, , $em] = $this->service();
        $user = (new User())->setEmail('person@example.com');
        $em->expects($this->once())->method('flush');
        $service->link($user, ['sub' => 'subject', 'email' => 'PERSON@example.com']);
        self::assertSame('subject', $user->getGoogleSubject());
    }

    public function testCannotLinkDifferentEmail(): void
    {
        [$service, , $em] = $this->service();
        $em->expects($this->never())->method('flush');
        self::expectExceptionMessage('auth.google.email_mismatch');
        $service->link((new User())->setEmail('person@example.com'), ['sub' => 'subject', 'email' => 'other@example.com']);
    }

    public function testCannotLinkGoogleIdentityOwnedByAnotherUser(): void
    {
        [$service, $users, $em] = $this->service();
        $existing = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($existing, 2);
        $users->method('findOneBy')->willReturn($existing);
        $em->expects($this->never())->method('flush');
        self::expectExceptionMessage('auth.google.link_conflict');
        $service->link((new User())->setEmail('person@example.com'), ['sub' => 'subject', 'email' => 'person@example.com']);
    }

    private function service(): array
    {
        $users = $this->createMock(UserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $plans = new SubscriptionPlanService($this->createMock(SubscriptionPlanConfigurationRepository::class), $em, $this->createMock(OrganizationMemberRepository::class));
        $mailer = $this->createMock(MailerService::class);
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $registrations = new UserRegistrationService($em, $this->createMock(UserPasswordHasherInterface::class), new ExtensionOnboardingPolicy(false, $plans),
            $this->createMock(NotificationService::class), $mailer, new AdminNotificationService($mailer, $urls, new NullLogger()), $urls, new NullLogger());

        return [new GoogleAccountService($users, $em, $registrations), $users, $em];
    }
}
