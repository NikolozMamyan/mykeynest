<?php

namespace App\Service;

use App\Entity\ExtensionClient;
use App\Entity\ExtensionInstallationChallenge;
use App\Entity\User;
use App\Repository\ExtensionClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ExtensionClientManager
{
    private const CHALLENGE_RESEND_AFTER = '2 minutes';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ExtensionClientRepository $extensionClientRepository,
        private ExtensionInstallationChallengeManager $extensionInstallationChallengeManager,
        private MailerService $mailerService,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        private SubscriptionPlanService $subscriptionPlans,
        #[Autowire('%kernel.environment%')]
        private string $environment
    ) {
    }

    /**
     * @return array{
     *     status: 'resolved',
     *     client: ExtensionClient,
     *     installationToken: ?string,
     *     isNew: bool
     * }|array{
     *     status: 'approval_required',
     *     message: string,
     *     challenge: ExtensionInstallationChallenge,
     *     delivery: 'email'|'development_url',
     *     developmentApproveUrl?: string
     * }|array{
     *     status: 'delivery_failed',
     *     message: string
     * }
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
        $existingClient = $this->extensionClientRepository->findOneByUserAndClientId($user, $clientId);

        if ($existingClient instanceof ExtensionClient) {
            $this->assertAllowed($existingClient);

            if (!$existingClient->getClientSecretHash()) {
                $plainInstallationToken = bin2hex(random_bytes(32));
                $existingClient->setClientSecretHash($this->hashInstallationToken($plainInstallationToken));
                $this->hydrateClient($existingClient, $deviceLabel, $browserName, $browserVersion, $osName, $osVersion, $extensionVersion, $manifestVersion, $originType, $ipAddress, $userAgent);
                $this->entityManager->flush();

                return [
                    'status' => 'resolved',
                    'client' => $existingClient,
                    'installationToken' => $plainInstallationToken,
                    'isNew' => false,
                ];
            }

            if (!$installationToken) {
                throw new \RuntimeException('Installation token manquant pour cette extension');
            }

            $this->assertInstallationTokenMatches($existingClient, $installationToken);
            $this->hydrateClient($existingClient, $deviceLabel, $browserName, $browserVersion, $osName, $osVersion, $extensionVersion, $manifestVersion, $originType, $ipAddress, $userAgent);
            $this->entityManager->flush();

            return [
                'status' => 'resolved',
                'client' => $existingClient,
                'installationToken' => null,
                'isNew' => false,
            ];
        }

        $this->assertNoBlockedFingerprintReuse($user, $deviceLabel, $browserName, $osName, $userAgent, $ipAddress);
        $existingCount = $this->countActiveClients($existingClients);
        $installationLimit = $this->subscriptionPlans->getLimit($user, SubscriptionPlanService::LIMIT_EXTENSION_INSTALLATIONS);

        if ($installationLimit === 0) {
            throw new \RuntimeException('L’extension navigateur n’est pas disponible avec ce plan.');
        }

        if ($existingCount === 0) {
            return $this->createClient($user, $clientId, $deviceLabel, $browserName, $browserVersion, $osName, $osVersion, $extensionVersion, $manifestVersion, $originType, $ipAddress, $userAgent);
        }

        if ($installationLimit !== null && $existingCount >= $installationLimit) {
            throw new \RuntimeException(sprintf(
                'Nouvelle installation refusée. Votre plan autorise %d installation(s) extension.',
                $installationLimit
            ));
        }

        $challenge = $this->extensionInstallationChallengeManager->findLatestByUserAndClientId($user, $clientId);

        if ($challenge instanceof ExtensionInstallationChallenge) {
            if ($challenge->isExpired() && $challenge->getStatus() === ExtensionInstallationChallenge::STATUS_PENDING) {
                $this->extensionInstallationChallengeManager->expire($challenge);
            } elseif ($challenge->isApproved() && !$challenge->isCompleted() && !$challenge->isExpired()) {
                $resolved = $this->createClient($user, $clientId, $deviceLabel, $browserName, $browserVersion, $osName, $osVersion, $extensionVersion, $manifestVersion, $originType, $ipAddress, $userAgent);
                $this->extensionInstallationChallengeManager->complete($challenge);

                return $resolved;
            } elseif ($challenge->isPending() && !$challenge->isExpired()) {
                if (!$this->shouldRenewPendingChallenge($challenge)) {
                    return [
                        'status' => 'approval_required',
                        'message' => 'Un e-mail de confirmation a déjà été envoyé pour autoriser cette installation.',
                        'challenge' => $challenge,
                        'delivery' => 'email',
                    ];
                }

                $this->extensionInstallationChallengeManager->expire($challenge);
            } elseif ($challenge->isRejected() && !$challenge->isExpired()) {
                throw new \RuntimeException('Cette installation a été refusée par e-mail.');
            }
        }

        [$challenge, $plainToken] = $this->extensionInstallationChallengeManager->createChallenge($user, $request, $clientId);
        [$approveUrl, $rejectUrl] = $this->buildChallengeUrls($plainToken);

        try {
            $this->sendApprovalEmail($user, $challenge, $approveUrl, $rejectUrl);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to send extension installation challenge email', [
                'userId' => $user->getId(),
                'challengeId' => $challenge->getPublicId(),
                'error' => $exception->getMessage(),
            ]);

            if ($this->environment === 'dev') {
                return [
                    'status' => 'approval_required',
                    'message' => 'L’e-mail n’a pas pu être envoyé. Utilisez le lien de validation de développement.',
                    'challenge' => $challenge,
                    'delivery' => 'development_url',
                    'developmentApproveUrl' => $approveUrl,
                ];
            }

            $this->extensionInstallationChallengeManager->expire($challenge);

            return [
                'status' => 'delivery_failed',
                'message' => 'L’e-mail de validation de l’installation n’a pas pu être envoyé. Veuillez réessayer dans quelques minutes.',
            ];
        }

        $result = [
            'status' => 'approval_required',
            'message' => 'Nous avons envoyé un e-mail pour autoriser cette installation.',
            'challenge' => $challenge,
            'delivery' => 'email',
        ];

        if ($this->environment === 'dev') {
            $result['developmentApproveUrl'] = $approveUrl;
        }

        return $result;
    }

    /**
     * Resolve the first installation authorized by an authenticated web pairing.
     * A retry for the same client rotates only that client's installation token.
     *
     * @return array{
     *     status: 'resolved',
     *     client: ExtensionClient,
     *     installationToken: string,
     *     isNew: bool
     * }
     */
    public function resolvePairingFromRequest(User $user, Request $request): array
    {
        $clientId = $this->extractRequiredHeader($request, 'X-Extension-Client-Id');
        $existingClient = $this->extensionClientRepository->findOneByUserAndClientId($user, $clientId);

        if ($existingClient instanceof ExtensionClient) {
            $this->assertAllowed($existingClient);

            $plainInstallationToken = bin2hex(random_bytes(32));
            $existingClient->setClientSecretHash($this->hashInstallationToken($plainInstallationToken));
            $this->hydrateClient(
                $existingClient,
                $this->cleanNullable($request->headers->get('X-Device-Label')),
                $this->cleanNullable($request->headers->get('X-Browser-Name')),
                $this->cleanNullable($request->headers->get('X-Browser-Version')),
                $this->cleanNullable($request->headers->get('X-OS-Name')),
                $this->cleanNullable($request->headers->get('X-OS-Version')),
                $this->cleanNullable($request->headers->get('X-Extension-Version')),
                $this->cleanNullable($request->headers->get('X-Extension-Manifest-Version')),
                $this->cleanNullable($request->headers->get('X-Extension-Origin')),
                $request->getClientIp(),
                $request->headers->get('User-Agent')
            );
            $this->entityManager->flush();

            return [
                'status' => 'resolved',
                'client' => $existingClient,
                'installationToken' => $plainInstallationToken,
                'isNew' => false,
            ];
        }

        $existingClients = $this->extensionClientRepository->findByUserOrderByLastSeen($user);
        $installationLimit = $this->subscriptionPlans->getLimit($user, SubscriptionPlanService::LIMIT_EXTENSION_INSTALLATIONS);
        if ($installationLimit === 0) {
            throw new \RuntimeException('L’extension navigateur n’est pas disponible avec ce plan.');
        }

        if ($installationLimit !== null && $this->countActiveClients($existingClients) >= $installationLimit) {
            $now = new \DateTimeImmutable();
            foreach ($existingClients as $installedClient) {
                if ($installedClient->isRevoked()) {
                    continue;
                }

                $installedClient->setIsRevoked(true);
                $installedClient->setRevokedAt($now);
                $installedClient->setRevokedReason('Remplacee lors d une nouvelle installation');
                $installedClient->touch();
            }
        }

        return $this->createClient(
            $user,
            $clientId,
            $this->cleanNullable($request->headers->get('X-Device-Label')),
            $this->cleanNullable($request->headers->get('X-Browser-Name')),
            $this->cleanNullable($request->headers->get('X-Browser-Version')),
            $this->cleanNullable($request->headers->get('X-OS-Name')),
            $this->cleanNullable($request->headers->get('X-OS-Version')),
            $this->cleanNullable($request->headers->get('X-Extension-Version')),
            $this->cleanNullable($request->headers->get('X-Extension-Manifest-Version')),
            $this->cleanNullable($request->headers->get('X-Extension-Origin')),
            $request->getClientIp(),
            $request->headers->get('User-Agent')
        );
    }

    public function assertAllowed(ExtensionClient $client): void
    {
        if ($client->isBlocked()) {
            throw new \RuntimeException('Cette extension est bloquée. Raison : ' . ($client->getBlockedReason() ?? 'Non spécifiée'));
        }

        if ($client->isRevoked()) {
            throw new \RuntimeException('Cette extension a été révoquée. Raison : ' . ($client->getRevokedReason() ?? 'Non spécifiée'));
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
        $client->setIsRevoked(false);
        $client->setRevokedAt(null);
        $client->setRevokedReason(null);
        $client->touch();
        $this->entityManager->flush();
    }

    /**
     * Revoked installations remain in the audit trail but no longer consume a plan slot.
     *
     * @param ExtensionClient[] $clients
     */
    private function countActiveClients(array $clients): int
    {
        return count(array_filter(
            $clients,
            static fn (ExtensionClient $client): bool => !$client->isRevoked(),
        ));
    }

    public function revoke(ExtensionClient $client, ?string $reason = null): void
    {
        $client->setIsRevoked(true);
        $client->setRevokedAt(new \DateTimeImmutable());
        $client->setRevokedReason($reason ?: 'Révoqué par l’utilisateur');
        $client->touch();
        $this->entityManager->flush();
    }

    public function delete(ExtensionClient $client): void
    {
        $this->entityManager->remove($client);
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

        if (!hash_equals($storedHash, $this->hashInstallationToken($plainToken))) {
            throw new \RuntimeException('Installation token invalide');
        }
    }

    private function hashInstallationToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * @return array{
     *     status: 'resolved',
     *     client: ExtensionClient,
     *     installationToken: string,
     *     isNew: true
     * }
     */
    private function createClient(User $user, string $clientId, ?string $deviceLabel, ?string $browserName, ?string $browserVersion, ?string $osName, ?string $osVersion, ?string $extensionVersion, ?string $manifestVersion, ?string $originType, ?string $ipAddress, ?string $userAgent): array
    {
        $plainInstallationToken = bin2hex(random_bytes(32));
        $client = new ExtensionClient();
        $client->setUser($user);
        $client->setClientId($clientId);
        $client->setClientSecretHash($this->hashInstallationToken($plainInstallationToken));
        $client->setFirstSeenAt(new \DateTimeImmutable());
        $this->hydrateClient($client, $deviceLabel, $browserName, $browserVersion, $osName, $osVersion, $extensionVersion, $manifestVersion, $originType, $ipAddress, $userAgent);
        $this->entityManager->persist($client);
        $this->entityManager->flush();

        return [
            'status' => 'resolved',
            'client' => $client,
            'installationToken' => $plainInstallationToken,
            'isNew' => true,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function buildChallengeUrls(string $plainToken): array
    {
        $approveUrl = $this->urlGenerator->generate('api_extension_installation_challenge_approve', ['token' => $plainToken], UrlGeneratorInterface::ABSOLUTE_URL);
        $rejectUrl = $this->urlGenerator->generate('api_extension_installation_challenge_reject', ['token' => $plainToken], UrlGeneratorInterface::ABSOLUTE_URL);

        return [$approveUrl, $rejectUrl];
    }

    private function sendApprovalEmail(User $user, ExtensionInstallationChallenge $challenge, string $approveUrl, string $rejectUrl): void
    {
        $this->mailerService->send(
            (string) $user->getEmail(),
            'Autorisez cette installation de l’extension',
            'emails/security/extension_installation_challenge.html.twig',
            [
                'user' => $user,
                'approveUrl' => $approveUrl,
                'rejectUrl' => $rejectUrl,
                'deviceLabel' => $challenge->getDeviceLabel(),
                'browserName' => $challenge->getBrowserName(),
                'browserVersion' => $challenge->getBrowserVersion(),
                'osName' => $challenge->getOsName(),
                'osVersion' => $challenge->getOsVersion(),
                'ip' => $challenge->getIpAddress(),
                'userAgent' => $challenge->getUserAgent(),
                'requestedAt' => $challenge->getCreatedAt(),
            ]
        );
    }

    private function shouldRenewPendingChallenge(ExtensionInstallationChallenge $challenge): bool
    {
        $createdAt = $challenge->getCreatedAt();

        return $createdAt === null || $createdAt <= new \DateTimeImmutable('-' . self::CHALLENGE_RESEND_AFTER);
    }

    private function hydrateClient(ExtensionClient $client, ?string $deviceLabel, ?string $browserName, ?string $browserVersion, ?string $osName, ?string $osVersion, ?string $extensionVersion, ?string $manifestVersion, ?string $originType, ?string $ipAddress, ?string $userAgent): void
    {
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

    private function assertNoBlockedFingerprintReuse(User $user, ?string $deviceLabel, ?string $browserName, ?string $osName, ?string $userAgent, ?string $ipAddress): void
    {
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
                throw new \RuntimeException('Nouvelle installation refusée : cette extension ressemble à une installation précédemment bloquée');
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
