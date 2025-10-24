<?php

namespace App\Controller\Api;


use App\Service\NotificationService;
use App\Repository\CredentialRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


final class BasicRequestController extends AbstractController
{
    #[Route('/api/credentials/length', name: 'api_credentials_length', methods: ['GET'])]
    public function credentialsLength(CredentialRepository $credentialRepo, Request $request): JsonResponse 
    {
       
        $user = $this->getUser();

         if (!$user) {
            throw $this->createNotFoundException('Utilisateur 404');
        }
       
       $count = $credentialRepo->countByUser($user);
       

       return $this->json(['count' => $count]);
    }

    #[Route('/api/notifications/length', name: 'notifications', methods: ['GET'])]
    public function notificationsLength(NotificationService $notificationService, Request $request): JsonResponse 
    {
       
        $user = $this->getUser();

         if (!$user) {
            throw $this->createNotFoundException('Utilisateur 404');
        }
       
       $count = $notificationService->getUnreadCount($user);

       return $this->json(['count' => $count]);
    }


        #[Route('/api/notifications', name: 'api_notifications')]
    public function apiNotifications(NotificationService $notificationService): JsonResponse
    {
        $user = $this->getUser();
        $notifications = 
        $notificationService->getRecentNotifications($user, 50);


         return $this->json(['notifications' => $notifications]);
    }

    
#[Route('/api/notifications/mark-all-read', name: 'api_notifications_mark_all_read', methods: ['POST'])]
public function markAllAsRead(NotificationService $notificationService): JsonResponse
{
    $user = $this->getUser();
    $notificationService->markAllAsRead($user);
    
    return $this->json(['success' => true]);
}
}
