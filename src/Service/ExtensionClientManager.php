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

    /**
     * @return array{client: ExtensionClient, installationToken: ?string, isNew: bool}
     */
    public function resolveFromRequest(User $user, Request $request): array
{
    $clientId = $this->extractRequiredHeader($request, 'X-Extension-Client-Id');
    $installationToken = $this->cleanNullable($request->headers->get('X-Extension-Installation-Token'));

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

    $existingClients = $this->extensionClientRepository->findByUserOrderByLastSeen($user);

    if (count($existingClients) > 1) {
        throw new \RuntimeException(
            'Configuration invalide : plusieurs installations extension existent déjà pour ce compte'
        );
    }

    $existingClient = $existingClients[0] ?? null;

    if ($existingClient instanceof ExtensionClient) {
        $this->assertAllowed($existingClient);

        if ($existingClient->getClientId() !== $clientId) {
            throw new \RuntimeException(
                'Nouvelle installation refusée. Une installation extension existe déjà pour ce compte.'
            );
        }

        if (!$existingClient->getClientSecretHash()) {
            $plainInstallationToken = bin2hex(random_bytes(32));
            $existingClient->setClientSecretHash($this->hashInstallationToken($plainInstallationToken));

            $this->hydrateClient(
                $existingClient,
                $deviceLabel,
                $browserName,
                $browserVersion,
                $osName,
                $osVersion,
                $extensionVersion,
                $manifestVersion,
                $originType,
                $ipAddress,
                $userAgent
            );

            $this->entityManager->flush();

            return [
                'client' => $existingClient,
                'installationToken' => $plainInstallationToken,
                'isNew' => false,
            ];
        }

        if (!$installationToken) {
            throw new \RuntimeException('Installation token manquant pour cette extension');
        }

        $this->assertInstallationTokenMatches($existingClient, $installationToken);

        $this->hydrateClient(
            $existingClient,
            $deviceLabel,
            $browserName,
            $browserVersion,
            $osName,
            $osVersion,
            $extensionVersion,
            $manifestVersion,
            $originType,
            $ipAddress,
            $userAgent
        );

        $this->entityManager->flush();

        return [
            'client' => $existingClient,
            'installationToken' => null,
            'isNew' => false,
        ];
    }

    $plainInstallationToken = bin2hex(random_bytes(32));

    $client = new ExtensionClient();
    $client->setUser($user);
    $client->setClientId($clientId);
    $client->setClientSecretHash($this->hashInstallationToken($plainInstallationToken));
    $client->setFirstSeenAt(new \DateTimeImmutable());

    $this->hydrateClient(
        $client,
        $deviceLabel,
        $browserName,
        $browserVersion,
        $osName,
        $osVersion,
        $extensionVersion,
        $manifestVersion,
        $originType,
        $ipAddress,
        $userAgent
    );

    $this->entityManager->persist($client);
    $this->entityManager->flush();

    return [
        'client' => $client,
        'installationToken' => $plainInstallationToken,
        'isNew' => true,
    ];
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
        $reason = $reason ?: 'Bloqué par l’utilisateur';

        $client->setIsBlocked(true);
        $client->setBlockedAt(new \DateTimeImmutable());
        $client->setBlockedReason($reason);

        $client->setIsRevoked(true);
        $client->setRevokedAt(new \DateTimeImmutable());
        $client->setRevokedReason($reason);

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

    public function rotateInstallationToken(ExtensionClient $client): string
    {
        $plainInstallationToken = bin2hex(random_bytes(32));
        $client->setClientSecretHash($this->hashInstallationToken($plainInstallationToken));
        $client->touch();

        $this->entityManager->flush();

        return $plainInstallationToken;
    }

    private function assertInstallationTokenMatches(ExtensionClient $client, string $plainToken): void
    {
        $storedHash = $client->getClientSecretHash();

        if (!$storedHash) {
            throw new \RuntimeException('Installation non initialisée correctement');
        }

        $incomingHash = $this->hashInstallationToken($plainToken);

        if (!hash_equals($storedHash, $incomingHash)) {
            throw new \RuntimeException('Installation token invalide');
        }
    }

    private function hashInstallationToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    private function hydrateClient(
        ExtensionClient $client,
        ?string $deviceLabel,
        ?string $browserName,
        ?string $browserVersion,
        ?string $osName,
        ?string $osVersion,
        ?string $extensionVersion,
        ?string $manifestVersion,
        ?string $originType,
        ?string $ipAddress,
        ?string $userAgent
    ): void {
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
    }

    private function assertNoBlockedFingerprintReuse(
        User $user,
        ?string $deviceLabel,
        ?string $browserName,
        ?string $osName,
        ?string $userAgent,
        ?string $ipAddress
    ): void {
        $blockedClients = $this->extensionClientRepository->findBlockedByUser($user);

        foreach ($blockedClients as $blockedClient) {
            $score = 0;

            if ($this->sameNormalized($blockedClient->getDeviceLabel(), $deviceLabel)) {
                $score += 3;
            }

            if ($this->sameNormalized($blockedClient->getBrowserName(), $browserName)) {
                $score += 2;
            }

            if ($this->sameNormalized($blockedClient->getOsName(), $osName)) {
                $score += 2;
            }

            if ($this->sameNormalized($blockedClient->getLastUserAgent(), $userAgent)) {
                $score += 4;
            }

            if ($blockedClient->getLastIpAddress() && $ipAddress && $blockedClient->getLastIpAddress() === $ipAddress) {
                $score += 1;
            }

            if ($score >= 5) {
                throw new \RuntimeException(
                    'Nouvelle installation refusée : cette extension ressemble à une installation précédemment bloquée'
                );
            }
        }
    }

    private function sameNormalized(?string $a, ?string $b): bool
    {
        $a = $this->normalizeFingerprintValue($a);
        $b = $this->normalizeFingerprintValue($b);

        return $a !== null && $b !== null && $a === $b;
    }

    private function normalizeFingerprintValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(mb_strtolower($value));
        $value = preg_replace('/\s+/', ' ', $value);

        return $value === '' ? null : $value;
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

        return $value === '' ? null : mb_substr($value, 0, 1000);
    }
}