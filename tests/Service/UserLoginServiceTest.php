<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\LoginChallenge;
use App\Entity\User;
use App\Entity\UserSession;
use App\Repository\OrganizationMemberRepository;
use App\Repository\SubscriptionPlanConfigurationRepository;
use App\Service\EmailTwoFactorPolicy;
use App\Service\ExtensionOnboardingPolicy;
use App\Service\LoginChallengeManager;
use App\Service\MailerService;
use App\Service\SessionManager;
use App\Service\SubscriptionPlanService;
use App\Service\UserDeviceManager;
use App\Service\UserLoginService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class UserLoginServiceTest extends TestCase
{
    public function testGoogleAndPasswordLoginCannotBypassBlockedDevice(): void
    {
        [$login, $sessions, $challenges] = $this->service(UserDeviceManager::STATE_BLOCKED);
        $sessions->expects($this->never())->method('createSession');
        $challenges->expects($this->never())->method('createChallenge');
        self::assertSame(403, $login->complete(new User(), Request::create('https://key-nest.com/login'))->getStatusCode());
    }

    public function testExistingAdministratorStillRequiresEmailApprovalOnUnknownDevice(): void
    {
        [$login, $sessions, $challenges] = $this->service(UserDeviceManager::STATE_UNKNOWN);
        $user = (new User())->setEmail('person@example.com')->setRoles(['ROLE_ADMIN']);
        $sessions->expects($this->never())->method('createSession');
        $challenges->expects($this->once())->method('createChallenge')->with($user, 'device')->willReturn([new LoginChallenge(), 'challenge-token']);
        $response = $login->complete($user, Request::create('https://key-nest.com/login'));
        self::assertSame(202, $response->getStatusCode());
        self::assertSame('email_verification_required', json_decode($response->getContent(), true)['status']);
        self::assertNotContains('AUTH_TOKEN', array_map(static fn ($cookie) => $cookie->getName(), $response->headers->getCookies()));
    }

    public function testNewPersonalAccountReceivesSecureSessionCookie(): void
    {
        [$login, $sessions, $challenges] = $this->service(UserDeviceManager::STATE_UNKNOWN);
        $session = (new UserSession())->setExpiresAt(new \DateTimeImmutable('+1 day'));
        $sessions->expects($this->once())->method('createSession')->willReturn([$session, 'auth-token', 'device']);
        $challenges->expects($this->never())->method('createChallenge');
        $response = $login->complete(new User(), Request::create('https://key-nest.com/register'), true);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('/app/credential', json_decode($response->getContent(), true)['redirectUrl']);
        $cookie = $response->headers->getCookies()[0];
        self::assertSame('AUTH_TOKEN', $cookie->getName());
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', $cookie->getSameSite());
    }

    /** @dataProvider redirectTargets */
    public function testOnlyAllowsLocalRedirects(mixed $target, ?string $expected): void
    {
        self::assertSame($expected, UserLoginService::safeNext($target));
    }

    public static function redirectTargets(): array
    {
        return [[null, null], [[], null], ['https://attacker.example', null], ['//attacker.example', null],
            ['/\\attacker.example', null], ["/\n/attacker.example", null], ['/', '/'], ['/app/credential?sort=name', '/app/credential?sort=name']];
    }

    private function service(string $deviceState): array
    {
        $sessions = $this->createMock(SessionManager::class);
        $sessions->method('getOrCreateCurrentDeviceId')->willReturn('device');
        $sessions->method('getDeviceAccessState')->willReturn($deviceState);
        $challenges = $this->createMock(LoginChallengeManager::class);
        $plans = new SubscriptionPlanService($this->createMock(SubscriptionPlanConfigurationRepository::class), $this->createMock(EntityManagerInterface::class), $this->createMock(OrganizationMemberRepository::class));
        $login = new UserLoginService($sessions, $challenges, new EmailTwoFactorPolicy($plans), new ExtensionOnboardingPolicy(false, $plans),
            $this->createMock(MailerService::class), $this->createMock(UrlGeneratorInterface::class), new NullLogger());

        return [$login, $sessions, $challenges];
    }
}
