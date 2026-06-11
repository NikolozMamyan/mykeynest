<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\UserDevice;
use App\Repository\UserDeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class UserDeviceManager
{
    public const STATE_UNKNOWN = 'unknown';
    public const STATE_TRUSTED = 'trusted';
    public const STATE_BLOCKED = 'blocked';

    private const TRUST_LIFETIME = '+90 days';

    public function __construct(
        private EntityManagerInterface $em,
        private UserDeviceRepository $repository,
        private RequestStack $requestStack
    ) {
    }

    public function getAccessState(User $user, string $deviceId): string
    {
        $device = $this->find($user, $deviceId);

        if ($device === null) {
            return self::STATE_UNKNOWN;
        }

        if ($device->isBlocked()) {
            return self::STATE_BLOCKED;
        }

        return $device->isTrusted() ? self::STATE_TRUSTED : self::STATE_UNKNOWN;
    }

    public function hasAnyDevice(User $user): bool
    {
        return $this->repository->count(['user' => $user]) > 0;
    }

    public function find(User $user, string $deviceId): ?UserDevice
    {
        if (!DeviceIdentifier::isValid($deviceId)) {
            return null;
        }

        return $this->repository->findOneBy([
            'user' => $user,
            'deviceId' => $deviceId,
        ]);
    }

    public function remember(
        User $user,
        string $deviceId,
        ?string $deviceName = null,
        bool $updateRequestMetadata = true
    ): UserDevice
    {
        if (!DeviceIdentifier::isValid($deviceId)) {
            throw new \InvalidArgumentException('Identifiant appareil invalide.');
        }

        $device = $this->find($user, $deviceId);

        if ($device === null) {
            $device = (new UserDevice())
                ->setUser($user)
                ->setDeviceId($deviceId);
            $this->em->persist($device);
        }

        if ($updateRequestMetadata) {
            $request = $this->requestStack->getCurrentRequest();
            $device
                ->setLastSeenAt(new \DateTimeImmutable())
                ->setDeviceName($deviceName ?? $device->getDeviceName())
                ->setUserAgent($request?->headers->get('User-Agent') ?? $device->getUserAgent())
                ->setIpAddress($request?->getClientIp() ?? $device->getIpAddress());
        }

        $this->em->flush();

        return $device;
    }

    public function trust(User $user, string $deviceId, ?string $deviceName = null): UserDevice
    {
        $device = $this->remember($user, $deviceId, $deviceName);

        if ($device->isBlocked()) {
            throw new \RuntimeException('Cet appareil est bloqué.');
        }

        $now = new \DateTimeImmutable();
        $device
            ->setTrustedAt($now)
            ->setTrustExpiresAt(new \DateTimeImmutable(self::TRUST_LIFETIME))
            ->setRevokedAt(null)
            ->setRevokedReason(null);

        $this->em->flush();

        return $device;
    }

    public function revokeTrust(User $user, string $deviceId, string $reason): void
    {
        $device = $this->remember($user, $deviceId, updateRequestMetadata: false);
        $device
            ->setRevokedAt(new \DateTimeImmutable())
            ->setRevokedReason($reason);

        $this->em->flush();
    }

    public function revokeAllTrust(User $user, ?string $exceptDeviceId = null, string $reason = 'logout_all'): int
    {
        $count = 0;
        $now = new \DateTimeImmutable();

        foreach ($this->repository->findBy(['user' => $user]) as $device) {
            $deviceId = $device->getDeviceId();
            if (
                $deviceId === null
                || ($exceptDeviceId !== null && hash_equals($exceptDeviceId, $deviceId))
                || $device->isBlocked()
            ) {
                continue;
            }

            $device
                ->setRevokedAt($now)
                ->setRevokedReason($reason);
            $count++;
        }

        $this->em->flush();

        return $count;
    }

    public function block(User $user, string $deviceId, ?string $reason = null): UserDevice
    {
        $device = $this->remember($user, $deviceId, updateRequestMetadata: false);
        $now = new \DateTimeImmutable();
        $reason ??= 'blocked_by_user';

        $device
            ->setRevokedAt($now)
            ->setRevokedReason($reason)
            ->setBlockedAt($now)
            ->setBlockedReason($reason);

        $this->em->flush();

        return $device;
    }

    public function unblock(User $user, string $deviceId): bool
    {
        $device = $this->find($user, $deviceId);

        if ($device === null || !$device->isBlocked()) {
            return false;
        }

        $device
            ->setBlockedAt(null)
            ->setBlockedReason(null)
            ->setRevokedAt(new \DateTimeImmutable())
            ->setRevokedReason('unblocked_requires_challenge');

        $this->em->flush();

        return true;
    }
}
