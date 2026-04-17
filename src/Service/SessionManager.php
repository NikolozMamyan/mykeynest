<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\UserSession;
use App\Repository\UserSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class SessionManager
{
    private const DEFAULT_SESSION_LIFETIME = '+30 days';
    private const MOBILE_SESSION_LIFETIME = '+90 days';

    public function __construct(
        private EntityManagerInterface $em,
        private UserSessionRepository $userSessionRepository,
        private RequestStack $requestStack,
        private DeviceIdentifier $deviceIdentifier
    ) {
    }

 public function createSession(User $user, ?string $deviceName = null): array
{
    $request = $this->requestStack->getCurrentRequest();
    $ipAddress = $request?->getClientIp();
    $userAgent = $request?->headers->get('User-Agent');
    $deviceId = $this->deviceIdentifier->getOrCreateCurrentDeviceId();

    $blockedSession = $this->findBlockedSessionByDeviceId($user, $deviceId);
    if ($blockedSession) {
        throw new \RuntimeException(
            'Cet appareil a été bloqué. Raison : ' . ($blockedSession->getBlockedReason() ?? 'Non spécifiée')
        );
    }

    $plainToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $plainToken);

    $session = new UserSession();
    $session->setUser($user);
    $session->setTokenHash($tokenHash);
    $session->setDeviceId($deviceId);
    $session->setDeviceName($deviceName);
    $session->setUserAgent($userAgent);
    $session->setIpAddress($ipAddress);
    $session->setExpiresAt($this->buildSessionExpiryDate($userAgent));
    $session->setLastActivityAt(new \DateTimeImmutable());

    $this->em->persist($session);
    $this->em->flush();

    return [$session, $plainToken, $deviceId];
}

    public function findActiveSessionByPlainToken(string $plainToken): ?UserSession
    {
        $tokenHash = hash('sha256', $plainToken);

        $session = $this->userSessionRepository->findOneBy([
            'tokenHash' => $tokenHash,
        ]);

        if (!$session) {
            return null;
        }

        if ($session->isBlocked()) {
            return null;
        }

        if ($session->isRevoked()) {
            return null;
        }

        if ($session->getExpiresAt() <= new \DateTimeImmutable()) {
            return null;
        }

        return $session;
    }

    public function touch(UserSession $session): void
    {
        $session->setLastActivityAt(new \DateTimeImmutable());
        $session->setExpiresAt($this->buildSessionExpiryDate($session->getUserAgent()));
        $this->em->flush();
    }

    public function revoke(UserSession $session, ?string $reason = null): void
    {
        $session->setIsRevoked(true);
        $session->setRevokedAt(new \DateTimeImmutable());
        $session->setRevokedReason($reason);
        $this->em->flush();
    }

    public function revokeDeviceSessions(User $user, string $deviceId, ?int $exceptSessionId = null, ?string $reason = null): int
    {
        $sessions = $this->userSessionRepository->findBy([
            'user' => $user,
            'deviceId' => $deviceId,
        ]);

        $count = 0;

        foreach ($sessions as $session) {
            if ($exceptSessionId !== null && $session->getId() === $exceptSessionId) {
                continue;
            }

            if ($session->isRevoked()) {
                continue;
            }

            $session->setIsRevoked(true);
            $session->setRevokedAt(new \DateTimeImmutable());
            $session->setRevokedReason($reason ?? 'revoked_by_user');
            $count++;
        }

        $this->em->flush();

        return $count;
    }

    public function blockDevice(User $user, string $deviceId, ?string $reason = null): int
    {
        $sessions = $this->userSessionRepository->findBy([
            'user' => $user,
            'deviceId' => $deviceId,
        ]);

        $count = 0;
        $now = new \DateTimeImmutable();

        foreach ($sessions as $session) {
            $session->setIsRevoked(true);
            $session->setRevokedAt($now);
            $session->setRevokedReason($reason ?? 'blocked_by_user');

            $session->setIsBlocked(true);
            $session->setBlockedAt($now);
            $session->setBlockedReason($reason);

            $count++;
        }

        $this->em->flush();

        return $count;
    }

    public function unblockDevice(User $user, string $deviceId): int
    {
        $sessions = $this->userSessionRepository->findBy([
            'user' => $user,
            'deviceId' => $deviceId,
        ]);

        $count = 0;

        foreach ($sessions as $session) {
            if (!$session->isBlocked()) {
                continue;
            }

            $session->setIsBlocked(false);
            $session->setBlockedAt(null);
            $session->setBlockedReason(null);
            $count++;
        }

        $this->em->flush();

        return $count;
    }

    public function revokeAllForUser(User $user, ?int $exceptSessionId = null): int
    {
        $sessions = $this->userSessionRepository->findBy([
            'user' => $user,
            'isRevoked' => false,
        ]);

        $count = 0;

        foreach ($sessions as $session) {
            if ($exceptSessionId !== null && $session->getId() === $exceptSessionId) {
                continue;
            }

            if ($session->getExpiresAt() <= new \DateTimeImmutable()) {
                continue;
            }

            $session->setIsRevoked(true);
            $session->setRevokedAt(new \DateTimeImmutable());
            $session->setRevokedReason('logout_all');
            $count++;
        }

        $this->em->flush();

        return $count;
    }

    public function findBlockedSessionByDeviceId(User $user, string $deviceId): ?UserSession
    {
        return $this->userSessionRepository->findOneBy([
            'user' => $user,
            'deviceId' => $deviceId,
            'isBlocked' => true,
        ]);
    }

    public function getCurrentDeviceId(): ?string
    {
        return $this->deviceIdentifier->getCurrentDeviceId();
    }
    public function isKnownDevice(User $user, string $deviceId): bool
{
    return $this->userSessionRepository->findOneBy([
        'user' => $user,
        'deviceId' => $deviceId,
    ]) !== null;
}

    public function isFirstSessionForUser(User $user): bool
    {
        return $this->userSessionRepository->count([
            'user' => $user,
        ]) === 0;
    }

    private function buildSessionExpiryDate(?string $userAgent): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->isMobileUserAgent($userAgent)
            ? self::MOBILE_SESSION_LIFETIME
            : self::DEFAULT_SESSION_LIFETIME);
    }

    public function getDeviceType(?string $userAgent): string
    {
        return $this->isMobileUserAgent($userAgent) ? 'mobile' : 'desktop';
    }

    public function getSessionLifetimeLabel(?string $userAgent): string
    {
        return $this->isMobileUserAgent($userAgent) ? '90 jours' : '30 jours';
    }

    private function isMobileUserAgent(?string $userAgent): bool
    {
        if (!is_string($userAgent) || trim($userAgent) === '') {
            return false;
        }

        return (bool) preg_match('/Android|iPhone|iPad|iPod/i', $userAgent);
    }
}
