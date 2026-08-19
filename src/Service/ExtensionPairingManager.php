<?php

namespace App\Service;

use App\Entity\ExtensionPairingChallenge;
use App\Entity\User;
use App\Repository\ExtensionPairingChallengeRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final class ExtensionPairingManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ExtensionPairingChallengeRepository $challengeRepository,
        private ExtensionClientManager $extensionClientManager
    ) {
    }

    /**
     * @return array{challenge: ExtensionPairingChallenge, plainToken: string}
     */
    public function issue(User $user, string $clientId): array
    {
        $clientId = $this->normalizeClientId($clientId);

        foreach ($this->challengeRepository->findOpenByUserAndClientId($user, $clientId) as $openChallenge) {
            $openChallenge->setStatus(ExtensionPairingChallenge::STATUS_EXPIRED);
        }

        $plainToken = bin2hex(random_bytes(32));
        $challenge = (new ExtensionPairingChallenge())
            ->setUser($user)
            ->setClientId($clientId)
            ->setTokenHash(hash('sha256', $plainToken));

        $this->entityManager->persist($challenge);
        $this->entityManager->flush();

        return [
            'challenge' => $challenge,
            'plainToken' => $plainToken,
        ];
    }

    /**
     * @return array{challenge: ExtensionPairingChallenge, apiToken: string, installationToken: string}
     */
    public function exchange(string $plainToken, Request $request): array
    {
        $plainToken = trim($plainToken);
        $clientId = $this->normalizeClientId((string) $request->headers->get('X-Extension-Client-Id', ''));

        if (!preg_match('/^[a-f0-9]{64}$/', $plainToken)) {
            throw new \RuntimeException('Code d association invalide.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($plainToken, $clientId, $request): array {
            $challenge = $this->challengeRepository->findByTokenHashForUpdate(hash('sha256', $plainToken));

            if (!$challenge instanceof ExtensionPairingChallenge
                || $challenge->getStatus() !== ExtensionPairingChallenge::STATUS_PENDING
                || $challenge->isExpired()
            ) {
                throw new \RuntimeException('Code d association invalide, expire ou deja utilise.');
            }

            if (!hash_equals((string) $challenge->getClientId(), $clientId)) {
                throw new \RuntimeException('Cette demande ne correspond pas a cette installation.');
            }

            $user = $challenge->getUser();
            if (!$user instanceof User) {
                throw new \RuntimeException('Ce parcours d association n est plus disponible.');
            }

            $resolved = $this->extensionClientManager->resolvePairingFromRequest($user, $request);
            $apiToken = $user->getApiExtensionToken();

            if (!is_string($apiToken) || $apiToken === '') {
                $user->regenerateApiExtensionToken();
                $apiToken = (string) $user->getApiExtensionToken();
            }

            $challenge->setStatus(ExtensionPairingChallenge::STATUS_EXCHANGED);
            $challenge->setExchangedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            return [
                'challenge' => $challenge,
                'apiToken' => $apiToken,
                'installationToken' => $resolved['installationToken'],
            ];
        });
    }

    public function complete(User $user, string $publicId, string $clientId): ExtensionPairingChallenge
    {
        $clientId = $this->normalizeClientId($clientId);

        return $this->entityManager->wrapInTransaction(function () use ($user, $publicId, $clientId): ExtensionPairingChallenge {
            $challenge = $this->challengeRepository->findForCompletion($user, trim($publicId), $clientId);

            if (!$challenge instanceof ExtensionPairingChallenge) {
                throw new \RuntimeException('Association introuvable.');
            }

            $this->entityManager->lock($challenge, LockMode::PESSIMISTIC_WRITE);

            if ($challenge->getStatus() === ExtensionPairingChallenge::STATUS_COMPLETED) {
                return $challenge;
            }

            $exchangedAt = $challenge->getExchangedAt();
            if ($challenge->getStatus() !== ExtensionPairingChallenge::STATUS_EXCHANGED
                || !$exchangedAt instanceof \DateTimeImmutable
                || $exchangedAt <= new \DateTimeImmutable('-10 minutes')
            ) {
                throw new \RuntimeException('Association non finalisee ou expiree.');
            }

            $challenge->setStatus(ExtensionPairingChallenge::STATUS_COMPLETED);
            $challenge->setCompletedAt(new \DateTimeImmutable());
            $user->completeExtensionOnboarding();
            $this->entityManager->flush();

            return $challenge;
        });
    }

    private function normalizeClientId(string $clientId): string
    {
        $clientId = trim($clientId);

        if (!preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $clientId)) {
            throw new \RuntimeException('Identifiant d extension invalide.');
        }

        return $clientId;
    }
}
