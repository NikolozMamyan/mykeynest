<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\CredentialRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class CredentialCountProvider
{
    private const CACHE_TTL = 600;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly CredentialRepository $credentialRepository,
    ) {
    }

    public function getForUser(User $user): int
    {
        return (int) $this->cache->get($this->key($user->getId()), function (ItemInterface $item) use ($user): int {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->credentialRepository->countByUser($user);
        });
    }

    public function invalidateForUser(User $user): void
    {
        $this->invalidateForUserId($user->getId());
    }

    public function invalidateForUserId(?int $userId): void
    {
        if ($userId !== null) {
            $this->cache->delete($this->key($userId));
        }
    }

    private function key(?int $userId): string
    {
        return 'credential_count.user.'.($userId ?? 0);
    }
}
