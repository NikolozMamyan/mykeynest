<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\UserSession;
use App\Repository\UserSessionRepository;
use App\Service\DeviceIdentifier;
use App\Service\SessionManager;
use App\Service\UserDeviceManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

final class SessionManagerTest extends TestCase
{
    public function testNormalLogoutPreservesDeviceTrust(): void
    {
        [$sessionManager, $entityManager, $userDeviceManager] = $this->createManager();
        $session = $this->createSession('logout');

        $entityManager->expects($this->once())->method('flush');
        $userDeviceManager->expects($this->never())->method('revokeTrust');

        $sessionManager->revoke($session, 'logout');

        self::assertTrue($session->isRevoked());
        self::assertSame('logout', $session->getRevokedReason());
    }

    public function testSecurityRevocationInvalidatesDeviceTrust(): void
    {
        [$sessionManager, $entityManager, $userDeviceManager] = $this->createManager();
        $session = $this->createSession('admin_revoked');
        $user = $session->getUser();
        $deviceId = $session->getDeviceId();

        $entityManager->expects($this->once())->method('flush');
        $userDeviceManager
            ->expects($this->once())
            ->method('revokeTrust')
            ->with($user, $deviceId, 'admin_revoked');

        $sessionManager->revoke($session, 'admin_revoked');

        self::assertTrue($session->isRevoked());
    }

    public function testRevokeAllInvalidatesTrustEvenWithoutActiveSessions(): void
    {
        $user = $this->createUser();
        $repository = $this->createMock(UserSessionRepository::class);
        $repository
            ->expects($this->once())
            ->method('findBy')
            ->with(['user' => $user, 'isRevoked' => false])
            ->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $userDeviceManager = $this->createMock(UserDeviceManager::class);
        $userDeviceManager
            ->expects($this->once())
            ->method('revokeAllTrust')
            ->with($user, null);

        $manager = new SessionManager(
            $entityManager,
            $repository,
            new RequestStack(),
            $this->createMock(DeviceIdentifier::class),
            $userDeviceManager
        );

        self::assertSame(0, $manager->revokeAllForUser($user));
    }

    public function testSessionCannotBeCreatedForUntrustedDevice(): void
    {
        $user = $this->createUser();
        $deviceId = str_repeat('f', 64);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');

        $userDeviceManager = $this->createMock(UserDeviceManager::class);
        $userDeviceManager
            ->expects($this->once())
            ->method('getAccessState')
            ->with($user, $deviceId)
            ->willReturn(UserDeviceManager::STATE_UNKNOWN);

        $manager = new SessionManager(
            $entityManager,
            $this->createMock(UserSessionRepository::class),
            new RequestStack(),
            $this->createMock(DeviceIdentifier::class),
            $userDeviceManager
        );

        $this->expectException(\RuntimeException::class);
        $manager->createSession($user, deviceId: $deviceId);
    }

    public function testUnknownDeviceIsTrustedWhenEmailTwoFactorAuthenticationIsDisabled(): void
    {
        $user = $this->createUser();
        $deviceId = str_repeat('a', 64);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(UserSession::class));
        $entityManager->expects($this->once())->method('flush');

        $userDeviceManager = $this->createMock(UserDeviceManager::class);
        $userDeviceManager
            ->expects($this->once())
            ->method('getAccessState')
            ->with($user, $deviceId)
            ->willReturn(UserDeviceManager::STATE_UNKNOWN);
        $userDeviceManager
            ->expects($this->once())
            ->method('trust')
            ->with($user, $deviceId, null);
        $userDeviceManager->expects($this->never())->method('remember');

        $manager = new SessionManager(
            $entityManager,
            $this->createMock(UserSessionRepository::class),
            new RequestStack(),
            $this->createMock(DeviceIdentifier::class),
            $userDeviceManager
        );

        [$session, $plainToken, $resolvedDeviceId] = $manager->createSession(
            $user,
            deviceId: $deviceId,
            trustUnknownDevice: true,
        );

        self::assertSame($user, $session->getUser());
        self::assertSame($deviceId, $resolvedDeviceId);
        self::assertSame(64, strlen($plainToken));
    }

    public function testBlockedDeviceRemainsBlockedWhenUnknownDeviceTrustIsAllowed(): void
    {
        $user = $this->createUser();
        $deviceId = str_repeat('b', 64);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');

        $userDeviceManager = $this->createMock(UserDeviceManager::class);
        $userDeviceManager
            ->expects($this->once())
            ->method('getAccessState')
            ->with($user, $deviceId)
            ->willReturn(UserDeviceManager::STATE_BLOCKED);
        $userDeviceManager
            ->expects($this->once())
            ->method('find')
            ->with($user, $deviceId)
            ->willReturn(null);
        $userDeviceManager->expects($this->never())->method('trust');

        $manager = new SessionManager(
            $entityManager,
            $this->createMock(UserSessionRepository::class),
            new RequestStack(),
            $this->createMock(DeviceIdentifier::class),
            $userDeviceManager
        );

        $this->expectException(\RuntimeException::class);
        $manager->createSession(
            $user,
            deviceId: $deviceId,
            trustUnknownDevice: true,
        );
    }

    public function testExistingDevicePreventsOnboardingAfterSessionHistoryWasDeleted(): void
    {
        $user = $this->createUser();
        $repository = $this->createMock(UserSessionRepository::class);
        $repository->expects($this->never())->method('count');

        $userDeviceManager = $this->createMock(UserDeviceManager::class);
        $userDeviceManager
            ->expects($this->once())
            ->method('hasAnyDevice')
            ->with($user)
            ->willReturn(true);

        $manager = new SessionManager(
            $this->createMock(EntityManagerInterface::class),
            $repository,
            new RequestStack(),
            $this->createMock(DeviceIdentifier::class),
            $userDeviceManager
        );

        self::assertFalse($manager->isFirstSessionForUser($user));
    }

    private function createManager(): array
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $userDeviceManager = $this->createMock(UserDeviceManager::class);

        $manager = new SessionManager(
            $entityManager,
            $this->createMock(UserSessionRepository::class),
            new RequestStack(),
            $this->createMock(DeviceIdentifier::class),
            $userDeviceManager
        );

        return [$manager, $entityManager, $userDeviceManager];
    }

    private function createSession(string $reason): UserSession
    {
        return (new UserSession())
            ->setUser($this->createUser())
            ->setTokenHash(hash('sha256', $reason))
            ->setDeviceId(str_repeat('e', 64))
            ->setExpiresAt(new \DateTimeImmutable('+1 day'));
    }

    private function createUser(): User
    {
        return (new User())
            ->setEmail('user@example.com')
            ->setPassword('hashed')
            ->setCompany('Acme');
    }
}
