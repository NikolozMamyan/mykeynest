<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserSessionRepository;
use App\Service\SessionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/sessions')]
final class SessionController extends AbstractController
{
    #[Route('', name: 'api_sessions_list', methods: ['GET'])]
    public function list(
        Request $request,
        UserSessionRepository $userSessionRepository,
        SessionManager $sessionManager
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié'], 401);
        }

        $currentToken = $request->cookies->get('AUTH_TOKEN');
        $currentHash = $currentToken ? hash('sha256', $currentToken) : null;
        $currentDeviceId = $sessionManager->getCurrentDeviceId();

        $sessions = $userSessionRepository->findBy(
            ['user' => $user],
            ['lastActivityAt' => 'DESC']
        );

        $devices = [];

        foreach ($sessions as $session) {
            $deviceId = $session->getDeviceId() ?? ('legacy-' . $session->getId());

            if (!isset($devices[$deviceId])) {
                $devices[$deviceId] = [
                    'deviceId' => $deviceId,
                    'deviceName' => $session->getDeviceName(),
                    'userAgent' => $session->getUserAgent(),
                    'deviceType' => $sessionManager->getDeviceType($session->getUserAgent()),
                    'sessionLifetimeLabel' => $sessionManager->getSessionLifetimeLabel($session->getUserAgent()),
                    'ipAddress' => $session->getIpAddress(),
                    'createdAt' => $session->getCreatedAt()?->format(DATE_ATOM),
                    'lastActivityAt' => $session->getLastActivityAt()?->format(DATE_ATOM),
                    'expiresAt' => $session->getExpiresAt()?->format(DATE_ATOM),
                    'isRevoked' => $session->isRevoked(),
                    'isBlocked' => $session->isBlocked(),
                    'blockedReason' => $session->getBlockedReason(),
                    'isCurrent' => $currentDeviceId !== null && $currentDeviceId === $session->getDeviceId(),
                    'sessionCount' => 0,
                    'sessions' => [],
                ];
            }

            $devices[$deviceId]['sessionCount']++;

            $devices[$deviceId]['sessions'][] = [
                'id' => $session->getId(),
                'createdAt' => $session->getCreatedAt()?->format(DATE_ATOM),
                'lastActivityAt' => $session->getLastActivityAt()?->format(DATE_ATOM),
                'expiresAt' => $session->getExpiresAt()?->format(DATE_ATOM),
                'isRevoked' => $session->isRevoked(),
                'isBlocked' => $session->isBlocked(),
                'isCurrentSession' => $currentHash === $session->getTokenHash(),
            ];

            if (
                $session->getLastActivityAt() !== null
                && (
                    $devices[$deviceId]['lastActivityAt'] === null
                    || $session->getLastActivityAt()->format(DATE_ATOM) > $devices[$deviceId]['lastActivityAt']
                )
            ) {
                $devices[$deviceId]['lastActivityAt'] = $session->getLastActivityAt()?->format(DATE_ATOM);
            }

            if ($currentHash === $session->getTokenHash()) {
                $devices[$deviceId]['isCurrent'] = true;
            }

            if ($session->isBlocked()) {
                $devices[$deviceId]['isBlocked'] = true;
                $devices[$deviceId]['blockedReason'] = $session->getBlockedReason();
            }

            if (!$devices[$deviceId]['deviceName'] && $session->getDeviceName()) {
                $devices[$deviceId]['deviceName'] = $session->getDeviceName();
            }

            if (!$devices[$deviceId]['userAgent'] && $session->getUserAgent()) {
                $devices[$deviceId]['userAgent'] = $session->getUserAgent();
                $devices[$deviceId]['deviceType'] = $sessionManager->getDeviceType($session->getUserAgent());
                $devices[$deviceId]['sessionLifetimeLabel'] = $sessionManager->getSessionLifetimeLabel($session->getUserAgent());
            }

            if (!$devices[$deviceId]['ipAddress'] && $session->getIpAddress()) {
                $devices[$deviceId]['ipAddress'] = $session->getIpAddress();
            }
        }

        return new JsonResponse(array_values($devices));
    }

    #[Route('/{id}', name: 'api_sessions_revoke', methods: ['DELETE'])]
    public function revoke(
        int $id,
        Request $request,
        UserSessionRepository $userSessionRepository,
        SessionManager $sessionManager
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié'], 401);
        }

        $session = $userSessionRepository->find($id);

        if (!$session || $session->getUser()?->getId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Session introuvable'], 404);
        }

        $currentToken = $request->cookies->get('AUTH_TOKEN');
        $currentHash = $currentToken ? hash('sha256', $currentToken) : null;
        $isCurrent = $currentHash === $session->getTokenHash();

        $sessionManager->revoke($session, 'revoked_by_user');

        $response = new JsonResponse(['message' => 'Session révoquée']);

        if ($isCurrent) {
            $response->headers->clearCookie('AUTH_TOKEN', '/');
        }

        return $response;
    }

    #[Route('/devices/{deviceId}/block', name: 'api_devices_block', methods: ['POST'])]
    public function blockDevice(
        string $deviceId,
        Request $request,
        UserSessionRepository $userSessionRepository,
        SessionManager $sessionManager
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié'], 401);
        }

        $currentDeviceId = $sessionManager->getCurrentDeviceId();

        if ($currentDeviceId !== null && hash_equals($currentDeviceId, $deviceId)) {
            return new JsonResponse([
                'error' => 'Vous ne pouvez pas bloquer l’appareil actuellement utilisé.',
            ], 400);
        }

        $targetSession = $userSessionRepository->findOneBy([
            'user' => $user,
            'deviceId' => $deviceId,
        ]);

        if (!$targetSession) {
            return new JsonResponse(['error' => 'Appareil introuvable'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $reason = $data['reason'] ?? 'Bloqué par l\'utilisateur';

        $count = $sessionManager->blockDevice($user, $deviceId, $reason);

        return new JsonResponse([
            'message' => 'Appareil bloqué. Les connexions existantes ont été révoquées.',
            'affectedSessions' => $count,
        ]);
    }

    #[Route('/devices/{deviceId}/unblock', name: 'api_devices_unblock', methods: ['POST'])]
    public function unblockDevice(
        string $deviceId,
        UserSessionRepository $userSessionRepository,
        SessionManager $sessionManager
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié'], 401);
        }

        $targetSession = $userSessionRepository->findOneBy([
            'user' => $user,
            'deviceId' => $deviceId,
        ]);

        if (!$targetSession) {
            return new JsonResponse(['error' => 'Appareil introuvable'], 404);
        }

        $count = $sessionManager->unblockDevice($user, $deviceId);

        return new JsonResponse([
            'message' => 'Appareil débloqué.',
            'affectedSessions' => $count,
        ]);
    }

    #[Route('/logout-all', name: 'api_sessions_logout_all', methods: ['POST'])]
    public function logoutAll(
        Request $request,
        SessionManager $sessionManager
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié'], 401);
        }

        $currentToken = $request->cookies->get('AUTH_TOKEN');
        $currentSession = $currentToken
            ? $sessionManager->findActiveSessionByPlainToken($currentToken)
            : null;

        $revokedCount = $sessionManager->revokeAllForUser(
            $user,
            $currentSession?->getId()
        );

        return new JsonResponse([
            'message' => 'Toutes les autres sessions ont été révoquées',
            'revokedCount' => $revokedCount,
        ]);
    }
}
