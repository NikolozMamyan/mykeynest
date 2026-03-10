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
        UserSessionRepository $userSessionRepository
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié'], 401);
        }

        $currentToken = $request->cookies->get('AUTH_TOKEN');
        $currentHash = $currentToken ? hash('sha256', $currentToken) : null;

        $sessions = $userSessionRepository->findBy(
            ['user' => $user],
            ['lastActivityAt' => 'DESC']
        );

        $data = [];

        foreach ($sessions as $session) {
            $data[] = [
                'id' => $session->getId(),
                'deviceName' => $session->getDeviceName(),
                'userAgent' => $session->getUserAgent(),
                'ipAddress' => $session->getIpAddress(),
                'createdAt' => $session->getCreatedAt()?->format(DATE_ATOM),
                'lastActivityAt' => $session->getLastActivityAt()?->format(DATE_ATOM),
                'expiresAt' => $session->getExpiresAt()?->format(DATE_ATOM),
                'isRevoked' => $session->isRevoked(),
                'isBlocked' => $session->isBlocked(), // ✅ AJOUTÉ
                'blockedReason' => $session->getBlockedReason(), // ✅ AJOUTÉ
                'isCurrent' => $currentHash === $session->getTokenHash(),
            ];
        }

        return new JsonResponse($data);
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

    // ✅ NOUVEAU : Bloquer une session
    #[Route('/{id}/block', name: 'api_sessions_block', methods: ['POST'])]
    public function block(
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

        $data = json_decode($request->getContent(), true);
        $reason = $data['reason'] ?? 'Bloqué par l\'utilisateur';

        $currentToken = $request->cookies->get('AUTH_TOKEN');
        $currentHash = $currentToken ? hash('sha256', $currentToken) : null;
        $isCurrent = $currentHash === $session->getTokenHash();

        $sessionManager->block($session, $reason);

        $response = new JsonResponse([
            'message' => 'Session bloquée. L\'appareil ne pourra plus se reconnecter.',
        ]);

        if ($isCurrent) {
            $response->headers->clearCookie('AUTH_TOKEN', '/');
        }

        return $response;
    }

    // ✅ NOUVEAU : Débloquer une session
    #[Route('/{id}/unblock', name: 'api_sessions_unblock', methods: ['POST'])]
    public function unblock(
        int $id,
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

        $sessionManager->unblock($session);

        return new JsonResponse([
            'message' => 'Session débloquée. L\'appareil peut à nouveau se connecter.',
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