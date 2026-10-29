<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Repository\OrganizationMemberRepository;
use App\Repository\SubscriptionPlanConfigurationRepository;
use App\Service\AdminNotificationService;
use App\Service\ExtensionOnboardingPolicy;
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

final class UserRegistrationServiceTest extends TestCase
{
    /** @dataProvider validProfiles */
    public function testResolvesCompanyFromProfile(array $data, ?string $expected): void
    {
        self::assertSame($expected, $this->service()->companyFor($data));
    }

    public static function validProfiles(): array
    {
        return [[[], null], [['accountType' => 'personal'], null],
            [['accountType' => 'personal', 'company' => 'Ignored'], null],
            [['accountType' => 'professional', 'company' => '  Company  '], 'Company'],
            [['company' => 'Legacy API client'], 'Legacy API client']];
    }

    /** @dataProvider invalidProfiles */
    public function testRejectsInvalidProfessionalProfiles(array $data): void
    {
        self::expectException(\InvalidArgumentException::class);
        $this->service()->companyFor($data);
    }

    public static function invalidProfiles(): array
    {
        return [[['accountType' => 'professional']], [['accountType' => 'professional', 'company' => '   ']],
            [['accountType' => 'professional', 'company' => str_repeat('a', 256)]],
            [['company' => []]], [['accountType' => 'unknown']]];
    }

    public function testGoogleRegistrationNeedsNeitherCompanyNorPassword(): void
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects($this->never())->method('hashPassword');
        $request = Request::create('/register');
        $request->setLocale('en');
        $user = $this->service($hasher)->register($request, 'person@gmail.com', null, null, 'google-subject');
        self::assertNull($user->getPassword());
        self::assertNull($user->getCompany());
        self::assertSame('google-subject', $user->getGoogleSubject());
        self::assertSame('en', $user->getLocale());
        self::assertNotEmpty($user->getCredentialEncryptionKey());
        self::assertNotEmpty($user->getApiExtensionToken());
    }

    public function testEmailRegistrationHashesPasswordAndKeepsCompany(): void
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects($this->once())->method('hashPassword')->with($this->isInstanceOf(User::class), 'password')->willReturn('hashed-password');
        $user = $this->service($hasher)->register(Request::create('/register'), 'person@example.com', 'password', 'Company');
        self::assertSame('hashed-password', $user->getPassword());
        self::assertSame('Company', $user->getCompany());
        self::assertNull($user->getGoogleSubject());
    }

    private function service(?UserPasswordHasherInterface $hasher = null): UserRegistrationService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $plans = new SubscriptionPlanService($this->createMock(SubscriptionPlanConfigurationRepository::class), $em, $this->createMock(OrganizationMemberRepository::class));
        $mailer = $this->createMock(MailerService::class);
        $urls = $this->createMock(UrlGeneratorInterface::class);

        return new UserRegistrationService($em, $hasher ?? $this->createMock(UserPasswordHasherInterface::class),
            new ExtensionOnboardingPolicy(false, $plans), $this->createMock(NotificationService::class),
            $mailer, new AdminNotificationService($mailer, $urls, new NullLogger()), $urls, new NullLogger());
    }
}
