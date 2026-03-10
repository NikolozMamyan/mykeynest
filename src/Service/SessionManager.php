<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\UserSession;
use App\Repository\UserSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class SessionManager
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserSessionRepository $userSessionRepository,
        private RequestStack $requestStack
    ) {
    }

    public function createSession(User $user, ?string $deviceName = null): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $ipAddress = $request?->getClientIp();
        $userAgent = $request?->headers->get('User-Agent');

        // ✅ Vérifie si cet appareil/IP est bloqué
        $blockedSession = $this->findBlockedSessionByFingerprint($user, $ipAddress, $userAgent);
        if ($blockedSession) {
            throw new \RuntimeException(
                'Cette session a été bloquée. Raison : ' . ($blockedSession->getBlockedReason() ?? 'Non spécifiée')
            );
        }

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);

        $session = new UserSession();
        $session->setUser($user);
        $session->setTokenHash($tokenHash);
        $session->setDeviceName($deviceName);
        $session->setUserAgent($userAgent);
        $session->setIpAddress($ipAddress);
        $session->setExpiresAt(new \DateTimeImmutable('+30 days'));
        $session->setLastActivityAt(new \DateTimeImmutable());

        $this->em->persist($session);
        $this->em->flush();

        return [$session, $plainToken];
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

        // ✅ Vérifie si la session est bloquée
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
        $session->setExpiresAt(new \DateTimeImmutable('+30 days'));
        $this->em->flush();
    }

    public function revoke(UserSession $session, ?string $reason = null): void
    {
        $session->setIsRevoked(true);
        $session->setRevokedAt(new \DateTimeImmutable());
        $session->setRevokedReason($reason);
        $this->em->flush();
    }

    // ✅ NOUVELLE FONCTION : Bloquer une session (empêche les reconnexions)
    public function block(UserSession $session, ?string $reason = null): void
    {
        // Révoque d'abord la session
        $session->setIsRevoked(true);
        $session->setRevokedAt(new \DateTimeImmutable());
        $session->setRevokedReason($reason);

        // Puis bloque pour empêcher les nouvelles connexions
        $session->setIsBlocked(true);
        $session->setBlockedAt(new \DateTimeImmutable());
        $session->setBlockedReason($reason);

        $this->em->flush();
    }

    // ✅ NOUVELLE FONCTION : Débloquer une session
    public function unblock(UserSession $session): void
    {
        $session->setIsBlocked(false);
        $session->setBlockedAt(null);
        $session->setBlockedReason(null);

        $this->em->flush();
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

    // ✅ NOUVELLE FONCTION : Trouve une session bloquée par fingerprint (IP + UserAgent)
    private function findBlockedSessionByFingerprint(User $user, ?string $ipAddress, ?string $userAgent): ?UserSession
    {
        if (!$ipAddress && !$userAgent) {
            return null;
        }

        return $this->userSessionRepository->findOneBy([
            'user' => $user,
            'ipAddress' => $ipAddress,
            'userAgent' => $userAgent,
            'isBlocked' => true,
        ]);
    }
}