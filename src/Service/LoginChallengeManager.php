<?php

namespace App\Service;

use App\Entity\LoginChallenge;
use App\Entity\User;
use App\Repository\LoginChallengeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class LoginChallengeManager
{
    public const COOKIE_NAME = 'PENDING_LOGIN_TOKEN';

    public function __construct(
        private EntityManagerInterface $em,
        private LoginChallengeRepository $repository,
        private RequestStack $requestStack
    ) {
    }

    public function createChallenge(User $user, string $deviceId, ?string $deviceName = null): array
    {
        $request = $this->requestStack->getCurrentRequest();

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);

        $challenge = new LoginChallenge();
        $challenge->setUser($user);
        $challenge->setTokenHash($tokenHash);
        $challenge->setDeviceId($deviceId);
        $challenge->setDeviceName($deviceName);
        $challenge->setUserAgent($request?->headers->get('User-Agent'));
        $challenge->setIpAddress($request?->getClientIp());

        $this->em->persist($challenge);
        $this->em->flush();

        return [$challenge, $plainToken];
    }

    public function findByPlainToken(string $plainToken): ?LoginChallenge
    {
        return $this->repository->findOneBy([
            'tokenHash' => hash('sha256', $plainToken),
        ]);
    }

    public function findValidByPlainToken(string $plainToken): ?LoginChallenge
    {
        $challenge = $this->findByPlainToken($plainToken);

        if (!$challenge) {
            return null;
        }

        if ($challenge->isExpired() && $challenge->getStatus() === LoginChallenge::STATUS_PENDING) {
            $challenge->setStatus(LoginChallenge::STATUS_EXPIRED);
            $this->em->flush();
        }

        if ($challenge->isExpired()) {
            return null;
        }

        return $challenge;
    }

    public function approve(LoginChallenge $challenge): void
    {
        if ($challenge->isExpired()) {
            $challenge->setStatus(LoginChallenge::STATUS_EXPIRED);
            $this->em->flush();
            return;
        }

        if (!$challenge->isPending()) {
            return;
        }

        $challenge->setStatus(LoginChallenge::STATUS_APPROVED);
        $challenge->setApprovedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    public function reject(LoginChallenge $challenge): void
    {
        if ($challenge->isExpired()) {
            $challenge->setStatus(LoginChallenge::STATUS_EXPIRED);
            $this->em->flush();
            return;
        }

        if (!$challenge->isPending()) {
            return;
        }

        $challenge->setStatus(LoginChallenge::STATUS_REJECTED);
        $challenge->setRejectedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    public function complete(LoginChallenge $challenge): void
    {
        $challenge->setStatus(LoginChallenge::STATUS_COMPLETED);
        $challenge->setCompletedAt(new \DateTimeImmutable());
        $this->em->flush();
    }
}