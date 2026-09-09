<?php

namespace App\Controller\Front;

use App\Entity\User;
use App\Service\NotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NotificationController extends AbstractController
{
    #[Route('/app/notifications', name: 'app_notifications', methods: ['GET'])]
    public function index(NotificationService $notificationService): Response
    {
        $user = $this->getAuthenticatedUser();
        $notifications = $notificationService->getRecentNotifications($user, 50);
        $unreadCount = $notificationService->getUnreadCount($user);

        if ($unreadCount > 0) {
            $notificationService->markAllAsRead($user);
        }

        return $this->render('notifications/index.html.twig', [
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
        ]);
    }

    #[Route('/app/notifications/{id}/delete', name: 'app_notification_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, NotificationService $notificationService): Response
    {
        if (!$this->isCsrfTokenValid('delete_notification_'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'app_notifications.page.flash_expired');

            return $this->redirectToRoute('app_notifications');
        }

        $notification = $notificationService->findByIdAndUser($id, $this->getAuthenticatedUser());
        if ($notification !== null) {
            $notificationService->deleteNotification($notification);
            $this->addFlash('success', 'app_notifications.page.flash_deleted');
        } else {
            $this->addFlash('error', 'app_notifications.page.flash_not_found');
        }

        return $this->redirectToRoute('app_notifications');
    }

    #[Route('/app/notifications/delete-all', name: 'app_notifications_delete_all', methods: ['POST'])]
    public function deleteAll(Request $request, NotificationService $notificationService): Response
    {
        if (!$this->isCsrfTokenValid('delete_all_notifications', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'app_notifications.page.flash_expired');

            return $this->redirectToRoute('app_notifications');
        }

        $notificationService->deleteAllForUser($this->getAuthenticatedUser());
        $this->addFlash('success', 'app_notifications.page.flash_all_deleted');

        return $this->redirectToRoute('app_notifications');
    }

    private function getAuthenticatedUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
