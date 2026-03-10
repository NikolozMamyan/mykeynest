<?php

namespace App\Service;

use App\Entity\ExtensionClient;
use App\Entity\User;
use App\Repository\ExtensionClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final class ExtensionClientManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ExtensionClientRepository $extensionClientRepository
    ) {}

    public function resolveFromRequest(User $user, Request $request): ExtensionClient
    {
        $clientId = $this->extractRequiredHeader($request, 'X-Extension-Client-Id');
        $deviceLabel = $this->cleanNullable($request->headers->get('X-Device-Label'));
        $browserName = $this->cleanNullable($request->headers->get('X-Browser-Name'));
        $browserVersion = $this->cleanNullable($request->headers->get('X-Browser-Version'));
        $osName = $this->cleanNullable($request->headers->get('X-OS-Name'));
        $osVersion = $this->cleanNullable($request->headers->get('X-OS-Version'));
        $extensionVersion = $this->cleanNullable($request->headers->get('X-Extension-Version'));
        $manifestVersion = $this->cleanNullable($request->headers->get('X-Extension-Manifest-Version'));
        $originType = $this->cleanNullable($request->headers->get('X-Extension-Origin'));
        $userAgent = $request->headers->get('User-Agent');
        $ipAddress = $request->getClientIp();

        $client = $this->extensionClientRepository->findOneByUserAndClientId($user, $clientId);

        if (!$client) {
            $client = new ExtensionClient();
            $client->setUser($user);
            $client->setClientId($clientId);
            $client->setFirstSeenAt(new \DateTimeImmutable());
        }

        $client->setDeviceLabel($deviceLabel);
        $client->setBrowserName($browserName);
        $client->setBrowserVersion($browserVersion);
        $client->setOsName($osName);
        $client->setOsVersion($osVersion);
        $client->setExtensionVersion($extensionVersion);
        $client->setManifestVersion($manifestVersion);
        $client->setOriginType($originType);
        $client->setLastIpAddress($ipAddress);
        $client->setLastUserAgent($userAgent);
        $client->touch();

        $this->entityManager->persist($client);
        $this->entityManager->flush();

        return $client;
    }

    public function assertAllowed(ExtensionClient $client): void
    {
        if ($client->isBlocked()) {
            throw new \RuntimeException(
                'Cette extension est bloquée. Raison : ' . ($client->getBlockedReason() ?? 'Non spécifiée')
            );
        }

        if ($client->isRevoked()) {
            throw new \RuntimeException(
                'Cette extension a été révoquée. Raison : ' . ($client->getRevokedReason() ?? 'Non spécifiée')
            );
        }
    }

    public function block(ExtensionClient $client, ?string $reason = null): void
    {
        $client->setIsBlocked(true);
        $client->setBlockedAt(new \DateTimeImmutable());
        $client->setBlockedReason($reason ?: 'Bloqué par l’utilisateur');

        $client->setIsRevoked(true);
        $client->setRevokedAt(new \DateTimeImmutable());
        $client->setRevokedReason($reason ?: 'Bloqué par l’utilisateur');

        $client->touch();

        $this->entityManager->flush();
    }

    public function unblock(ExtensionClient $client): void
    {
        $client->setIsBlocked(false);
        $client->setBlockedAt(null);
        $client->setBlockedReason(null);

        $client->touch();

        $this->entityManager->flush();
    }

    public function revoke(ExtensionClient $client, ?string $reason = null): void
    {
        $client->setIsRevoked(true);
        $client->setRevokedAt(new \DateTimeImmutable());
        $client->setRevokedReason($reason ?: 'Révoqué par l’utilisateur');
        $client->touch();

        $this->entityManager->flush();
    }

    private function extractRequiredHeader(Request $request, string $name): string
    {
        $value = trim((string) $request->headers->get($name, ''));

        if ($value === '') {
            throw new \RuntimeException(sprintf('Header requis manquant : %s', $name));
        }

        if (mb_strlen($value) > 128) {
            throw new \RuntimeException(sprintf('Header invalide : %s', $name));
        }

        return $value;
    }

    private function cleanNullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}