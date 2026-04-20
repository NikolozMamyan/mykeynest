<?php

namespace App\Controller\Api;


use App\Entity\Notification;
use App\Entity\User;
use App\Service\NotificationService;
use App\Repository\CredentialRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


final class BasicRequestController extends AbstractController
{
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
            'title' => $notification->getTitle(),
            'message' => $notification->getMessage(),
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
    public function credentialsLength(CredentialRepository $credentialRepo, Request $request): JsonResponse 
    {
        $user = $this->requireAuthenticatedUser();

       $count = $credentialRepo->countByUser($user);
       

       return $this->json(['count' => $count]);
    }

    #[Route('/api/notifications/length', name: 'notifications', methods: ['GET'])]
    public function notificationsLength(NotificationService $notificationService, Request $request): JsonResponse 
    {
        $user = $this->requireAuthenticatedUser();

       $count = $notificationService->getUnreadCount($user);

       return $this->json(['count' => $count]);
    }


        #[Route('/api/notifications', name: 'api_notifications')]
    public function apiNotifications(NotificationService $notificationService): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $notifications = $notificationService->getRecentNotifications($user, 50);
        $payload = array_map(fn (Notification $notification) => $this->normalizeNotification($notification), $notifications);

         return $this->json(['notifications' => $payload]);
    }

    
#[Route('/api/notifications/mark-all-read', name: 'api_notifications_mark_all_read', methods: ['POST'])]
public function markAllAsRead(NotificationService $notificationService): JsonResponse
{
    $user = $this->requireAuthenticatedUser();
    $notificationService->markAllAsRead($user);
    
    return $this->json(['success' => true]);
}
}
