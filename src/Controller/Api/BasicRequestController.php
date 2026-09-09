<?php

namespace App\Controller\Api;

use App\Entity\Notification;
use App\Entity\User;
use App\Service\CredentialCountProvider;
use App\Service\NotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BasicRequestController extends AbstractController
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    private function requireAuthenticatedUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        return $user;
    }

    private function normalizeNotification(Notification $notification): array
    {
        return [
            'id' => $notification->getId(),
            'title' => $this->translator->trans($notification->getTitle() ?? ''),
            'message' => $this->translator->trans($notification->getMessage() ?? ''),
            'type' => $notification->getType(),
            'isRead' => (bool) $notification->getIsRead(),
            'createdAt' => $notification->getCreatedAt()?->format(DATE_ATOM),
            'readAt' => $notification->getReadAt()?->format(DATE_ATOM),
            'actionUrl' => $notification->getActionUrl(),
            'icon' => $notification->getIcon(),
            'priority' => $notification->getPriority(),
            'relatedEntityId' => $notification->getRelatedEntityId(),
            'relatedEntityType' => $notification->getRelatedEntityType(),
            'timeAgo' => $notification->getCreatedAt() ? $notification->getTimeAgo() : '',
        ];
    }

    #[Route('/api/credentials/length', name: 'api_credentials_length', methods: ['GET'])]
    public function credentialsLength(CredentialCountProvider $credentialCounts): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();

        return $this->json(['count' => $credentialCounts->getForUser($user)]);
    }

    #[Route('/api/notifications/summary', name: 'api_notifications_summary', methods: ['GET'])]
    #[Route('/api/notifications/length', name: 'notifications', methods: ['GET'])]
    public function notificationsLength(NotificationService $notificationService): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();

        $count = $notificationService->getUnreadCount($user);

        return $this->json(['count' => $count]);
    }

    #[Route('/api/notifications', name: 'api_notifications', methods: ['GET'])]
    public function apiNotifications(NotificationService $notificationService, Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $limit = max(1, min(20, $request->query->getInt('limit', 12)));
        $notifications = $notificationService->getRecentNotifications($user, $limit);
        $payload = array_map(fn (Notification $notification) => $this->normalizeNotification($notification), $notifications);

        return $this->json([
            'notifications' => $payload,
            'unreadCount' => $notificationService->getUnreadCount($user),
        ]);
    }

    #[Route('/api/notifications/mark-all-read', name: 'api_notifications_mark_all_read', methods: ['POST'])]
    public function markAllAsRead(NotificationService $notificationService): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $notificationService->markAllAsRead($user);

        return $this->json(['success' => true]);
    }

    #[Route('/api/notifications/{id}/read', name: 'api_notification_read', methods: ['POST'])]
    public function markAsRead(int $id, NotificationService $notificationService): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $notification = $notificationService->findByIdAndUser($id, $user);

        if (!$notification instanceof Notification) {
            return $this->json(['success' => false], 404);
        }

        $notificationService->markAsRead($notification);

        return $this->json(['success' => true]);
    }

    #[Route('/api/notifications/{id}', name: 'api_notification_delete', methods: ['DELETE'])]
    public function deleteNotification(int $id, NotificationService $notificationService): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $notification = $notificationService->findByIdAndUser($id, $user);

        if (!$notification instanceof Notification) {
            return $this->json(['success' => false, 'message' => 'Notification introuvable.'], 404);
        }

        $notificationService->deleteNotification($notification);

        return $this->json(['success' => true]);
    }
}
