<?php

namespace App\Controller\Front;

use App\Service\NotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class NotificationController extends AbstractController
{
    #[Route('/app/notifications', name: 'app_notifications')]
    public function index(NotificationService $notificationService): Response
    {
        $user = $this->getUser();
        $notifications = 
        $notificationService->getRecentNotifications($user, 50);
        $notificationService->markAllAsRead($user);
        return $this->render('notifications/index.html.twig', [
            'notifications' => $notifications,
        ]);
    }

    #[Route('/app/notifications/{id}/delete', name: 'app_notification_delete', methods: ['POST'])]
public function delete(int $id, NotificationService $notificationService): Response
{
    $user = $this->getUser();
    $notification = $notificationService->findByIdAndUser($id, $user);

    if ($notification) {
        $notificationService->deleteNotification($notification);
        $this->addFlash('success', 'Notification deleted.');
    }

    return $this->redirectToRoute('app_notifications');
}

#[Route('/app/notifications/delete-all', name: 'app_notifications_delete_all', methods: ['POST'])]
public function deleteAll(NotificationService $notificationService): Response
{
    $user = $this->getUser();
    foreach ($notificationService->getRecentNotifications($user, 1000) as $notif) {
        $notificationService->deleteNotification($notif);
    }

    $this->addFlash('success', 'All notifications deleted.');
    return $this->redirectToRoute('app_notifications');
}

}