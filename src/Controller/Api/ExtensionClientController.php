<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\ExtensionClientRepository;
use App\Service\ExtensionClientManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/extension-clients')]
final class ExtensionClientController extends AbstractController
{
    #[Route('', name: 'api_extension_clients_list', methods: ['GET'])]
    public function list(
        ExtensionClientRepository $extensionClientRepository
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié'], 401);
        }

        $clients = $extensionClientRepository->findByUserOrderByLastSeen($user);

        $data = array_map(
            static fn($client) => [
                'id' => $client->getId(),
                'clientId' => $client->getClientId(),
                'clientSecretConfigured' => $client->getClientSecretHash() !== null,
                'deviceLabel' => $client->getDeviceLabel(),
                'browserName' => $client->getBrowserName(),
                'browserVersion' => $client->getBrowserVersion(),
                'osName' => $client->getOsName(),
                'osVersion' => $client->getOsVersion(),
                'extensionVersion' => $client->getExtensionVersion(),
                'manifestVersion' => $client->getManifestVersion(),
                'originType' => $client->getOriginType(),
                'lastIpAddress' => $client->getLastIpAddress(),
                'lastUserAgent' => $client->getLastUserAgent(),
                'createdAt' => $client->getCreatedAt()?->format(DATE_ATOM),
                'firstSeenAt' => $client->getFirstSeenAt()?->format(DATE_ATOM),
                'lastSeenAt' => $client->getLastSeenAt()?->format(DATE_ATOM),
                'isBlocked' => $client->isBlocked(),
                'blockedAt' => $client->getBlockedAt()?->format(DATE_ATOM),
                'blockedReason' => $client->getBlockedReason(),
                'isRevoked' => $client->isRevoked(),
                'revokedAt' => $client->getRevokedAt()?->format(DATE_ATOM),
                'revokedReason' => $client->getRevokedReason(),
            ],
            $clients
        );

        return new JsonResponse($data);
    }

    #[Route('/{id}/block', name: 'api_extension_clients_block', methods: ['POST'])]
    public function block(
        int $id,
        Request $request,
        ExtensionClientRepository $extensionClientRepository,
        ExtensionClientManager $extensionClientManager
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié'], 401);
        }

        $client = $extensionClientRepository->find($id);

        if (!$client || $client->getUser()?->getId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Installation introuvable'], 404);
        }

        $payload = json_decode($request->getContent(), true);
        $reason = is_array($payload) && isset($payload['reason']) && is_string($payload['reason']) && trim($payload['reason']) !== ''
            ? trim($payload['reason'])
            : 'Bloqué par l’utilisateur';

        $extensionClientManager->block($client, $reason);

        return new JsonResponse([
            'message' => 'Installation bloquée. Cette extension ne pourra plus appeler l’API.',
        ]);
    }

    #[Route('/{id}/unblock', name: 'api_extension_clients_unblock', methods: ['POST'])]
    public function unblock(
        int $id,
        ExtensionClientRepository $extensionClientRepository,
        ExtensionClientManager $extensionClientManager
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié'], 401);
        }

        $client = $extensionClientRepository->find($id);

        if (!$client || $client->getUser()?->getId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Installation introuvable'], 404);
        }

        $extensionClientManager->unblock($client);

        return new JsonResponse([
            'message' => 'Installation débloquée.',
        ]);
    }

    #[Route('/{id}/revoke', name: 'api_extension_clients_revoke', methods: ['POST'])]
    public function revoke(
        int $id,
        Request $request,
        ExtensionClientRepository $extensionClientRepository,
        ExtensionClientManager $extensionClientManager
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié'], 401);
        }

        $client = $extensionClientRepository->find($id);

        if (!$client || $client->getUser()?->getId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Installation introuvable'], 404);
        }

        $payload = json_decode($request->getContent(), true);
        $reason = is_array($payload) && isset($payload['reason']) && is_string($payload['reason']) && trim($payload['reason']) !== ''
            ? trim($payload['reason'])
            : 'Révoqué par l’utilisateur';

        $extensionClientManager->revoke($client, $reason);

        return new JsonResponse([
            'message' => 'Installation révoquée.',
        ]);
    }

    #[Route('/{id}/rotate-installation-token', name: 'api_extension_clients_rotate_installation_token', methods: ['POST'])]
    public function rotateInstallationToken(
        int $id,
        ExtensionClientRepository $extensionClientRepository,
        ExtensionClientManager $extensionClientManager
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié'], 401);
        }

        $client = $extensionClientRepository->find($id);

        if (!$client || $client->getUser()?->getId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Installation introuvable'], 404);
        }

        $plainInstallationToken = $extensionClientManager->rotateInstallationToken($client);

        return new JsonResponse([
            'message' => 'Le token d’installation a été régénéré.',
            'installationToken' => $plainInstallationToken,
            'warning' => 'Mettez à jour immédiatement l’extension concernée avec ce nouveau token.',
        ]);
    }
}