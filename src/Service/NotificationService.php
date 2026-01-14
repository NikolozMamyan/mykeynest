<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class NotificationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private NotificationRepository $notificationRepository,
        private ?LoggerInterface $logger = null
    ) {}

    /**
     * Create a new notification
     */
    public function createNotification(
        User $user,
        string $title,
        ?string $message = null,
        string $type = Notification::TYPE_INFO,
        ?string $actionUrl = null,
        ?string $icon = null,
        string $priority = Notification::PRIORITY_NORMAL,
        string $uniqueKey
    ): Notification {
        try {
            $notification = new Notification();
            $notification
                ->setUser($user)
                ->setTitle($title)
                ->setMessage($message)
                ->setType($type)
                ->setActionUrl($actionUrl)
                ->setIcon($icon)
                ->setPriority($priority)
                ->setUniqueKey($uniqueKey);

            $this->entityManager->persist($notification);
            $this->entityManager->flush();

            $this->logger?->info('Notification created', [
                'user_id' => $user->getId(),
                'title' => $title,
                'type' => $type
            ]);

            return $notification;
        } catch (\Exception $e) {
            $this->logger?->error('Failed to create notification', [
                'user_id' => $user->getId(),
                'title' => $title,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create a notification linked to an entity
     */
    public function createEntityNotification(
        User $user,
        string $title,
        object $relatedEntity,
        ?string $message = null,
        string $type = Notification::TYPE_INFO,
        ?string $actionUrl = null,
        ?string $icon = null,
        string $priority = Notification::PRIORITY_NORMAL
    ): Notification {
        try {
            // Validate entity has an ID
            if (!method_exists($relatedEntity, 'getId') || !$relatedEntity->getId()) {
                throw new \InvalidArgumentException('Related entity must have a valid ID');
            }

            $notification = new Notification();
            $notification
                ->setUser($user)
                ->setTitle($title)
                ->setMessage($message)
                ->setType($type)
                ->setActionUrl($actionUrl)
                ->setIcon($icon)
                ->setPriority($priority)
                ->setRelatedEntityId($relatedEntity->getId())
                ->setRelatedEntityType(get_class($relatedEntity));

            $this->entityManager->persist($notification);
            $this->entityManager->flush();

            $this->logger?->info('Entity notification created', [
                'user_id' => $user->getId(),
                'title' => $title,
                'entity_type' => get_class($relatedEntity),
                'entity_id' => $relatedEntity->getId()
            ]);

            return $notification;
        } catch (\Exception $e) {
            $this->logger?->error('Failed to create entity notification', [
                'user_id' => $user->getId(),
                'title' => $title,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create multiple notifications in batch (more efficient)
     */
    public function createBatchNotifications(array $users, string $title, ?string $message = null, string $type = Notification::TYPE_INFO, ?string $actionUrl = null, ?string $icon = null): int
    {
        try {
            $count = 0;
            
            foreach ($users as $user) {
                if (!$user instanceof User) {
                    continue;
                }

                $notification = new Notification();
                $notification
                    ->setUser($user)
                    ->setTitle($title)
                    ->setMessage($message)
                    ->setType($type)
                    ->setActionUrl($actionUrl)
                    ->setIcon($icon);

                $this->entityManager->persist($notification);
                $count++;
            }

            $this->entityManager->flush();

            $this->logger?->info('Batch notifications created', [
                'count' => $count,
                'title' => $title
            ]);

            return $count;
        } catch (\Exception $e) {
            $this->logger?->error('Failed to create batch notifications', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Mark a notification as read
     */
    public function markAsRead(Notification $notification): void
    {
        try {
            if ($notification->isRead()) {
                return; // Already read, no need to update
            }

            $notification->markAsRead();
            $this->entityManager->flush();

            $this->logger?->debug('Notification marked as read', [
                'notification_id' => $notification->getId()
            ]);
        } catch (\Exception $e) {
            $this->logger?->error('Failed to mark notification as read', [
                'notification_id' => $notification->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Mark multiple notifications as read
     */
    public function markMultipleAsRead(array $notifications): void
    {
        try {
            $count = 0;
            foreach ($notifications as $notification) {
                if ($notification instanceof Notification && !$notification->isRead()) {
                    $notification->markAsRead();
                    $count++;
                }
            }

            if ($count > 0) {
                $this->entityManager->flush();
                
                $this->logger?->info('Multiple notifications marked as read', [
                    'count' => $count
                ]);
            }
        } catch (\Exception $e) {
            $this->logger?->error('Failed to mark multiple notifications as read', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Mark all notifications of a user as read
     */
    public function markAllAsRead(User $user): int
    {
        try {
            $count = $this->notificationRepository->markAllAsReadByUser($user);
            
            $this->logger?->info('All notifications marked as read', [
                'user_id' => $user->getId(),
                'count' => $count
            ]);

            return $count;
        } catch (\Exception $e) {
            $this->logger?->error('Failed to mark all notifications as read', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Delete a notification
     */
    public function deleteNotification(Notification $notification): void
    {
        try {
            $this->entityManager->remove($notification);
            $this->entityManager->flush();

            $this->logger?->info('Notification deleted', [
                'notification_id' => $notification->getId(),
                'user_id' => $notification->getUser()->getId()
            ]);
        } catch (\Exception $e) {
            $this->logger?->error('Failed to delete notification', [
                'notification_id' => $notification->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Delete multiple notifications
     */
    public function deleteMultipleNotifications(array $notifications): int
    {
        try {
            $count = 0;
            foreach ($notifications as $notification) {
                if ($notification instanceof Notification) {
                    $this->entityManager->remove($notification);
                    $count++;
                }
            }

            if ($count > 0) {
                $this->entityManager->flush();
                
                $this->logger?->info('Multiple notifications deleted', [
                    'count' => $count
                ]);
            }

            return $count;
        } catch (\Exception $e) {
            $this->logger?->error('Failed to delete multiple notifications', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Delete all notifications for a user
     */
    public function deleteAllForUser(User $user): int
    {
        try {
            $count = $this->notificationRepository->deleteAllByUser($user);
            
            $this->logger?->info('All notifications deleted for user', [
                'user_id' => $user->getId(),
                'count' => $count
            ]);

            return $count;
        } catch (\Exception $e) {
            $this->logger?->error('Failed to delete all notifications for user', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get unread notifications count
     */
    public function getUnreadCount(User $user): int
    {
        try {
            return $this->notificationRepository->countUnreadByUser($user);
        } catch (\Exception $e) {
            $this->logger?->error('Failed to get unread count', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Get recent notifications
     */
    public function getRecentNotifications(User $user, int $limit = 10): array
    {
        try {
            return $this->notificationRepository->findRecentByUser($user, $limit);
        } catch (\Exception $e) {
            $this->logger?->error('Failed to get recent notifications', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get unread notifications only
     */
    public function getUnreadNotifications(User $user, int $limit = 10): array
    {
        try {
            return $this->notificationRepository->findBy(
                ['user' => $user, 'isRead' => false],
                ['createdAt' => 'DESC'],
                $limit
            );
        } catch (\Exception $e) {
            $this->logger?->error('Failed to get unread notifications', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get notifications by type
     */
    public function getNotificationsByType(User $user, string $type, int $limit = 10): array
    {
        try {
            return $this->notificationRepository->findBy(
                ['user' => $user, 'type' => $type],
                ['createdAt' => 'DESC'],
                $limit
            );
        } catch (\Exception $e) {
            $this->logger?->error('Failed to get notifications by type', [
                'user_id' => $user->getId(),
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Find notification by ID and user
     */
    public function findByIdAndUser(int $id, User $user): ?Notification
    {
        try {
            return $this->notificationRepository->findOneBy([
                'id' => $id,
                'user' => $user
            ]);
        } catch (\Exception $e) {
            $this->logger?->error('Failed to find notification', [
                'notification_id' => $id,
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Check if user has unread notifications
     */
    public function hasUnreadNotifications(User $user): bool
    {
        return $this->getUnreadCount($user) > 0;
    }

    /**
     * Clean up old notifications
     */
    public function cleanupOldNotifications(int $daysOld = 30): int
    {
        try {
            $count = $this->notificationRepository->deleteOldReadNotifications($daysOld);
            
            $this->logger?->info('Old notifications cleaned up', [
                'days_old' => $daysOld,
                'count' => $count
            ]);

            return $count;
        } catch (\Exception $e) {
            $this->logger?->error('Failed to cleanup old notifications', [
                'days_old' => $daysOld,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Get notification statistics for a user
     */
    public function getStatistics(User $user): array
    {
        try {
            return [
                'total' => $this->notificationRepository->count(['user' => $user]),
                'unread' => $this->getUnreadCount($user),
                'by_type' => [
                    'info' => $this->notificationRepository->count(['user' => $user, 'type' => Notification::TYPE_INFO]),
                    'success' => $this->notificationRepository->count(['user' => $user, 'type' => Notification::TYPE_SUCCESS]),
                    'warning' => $this->notificationRepository->count(['user' => $user, 'type' => Notification::TYPE_WARNING]),
                    'error' => $this->notificationRepository->count(['user' => $user, 'type' => Notification::TYPE_ERROR]),
                ],
                'by_priority' => [
                    'low' => $this->notificationRepository->count(['user' => $user, 'priority' => Notification::PRIORITY_LOW]),
                    'normal' => $this->notificationRepository->count(['user' => $user, 'priority' => Notification::PRIORITY_NORMAL]),
                    'high' => $this->notificationRepository->count(['user' => $user, 'priority' => Notification::PRIORITY_HIGH]),
                ]
            ];
        } catch (\Exception $e) {
            $this->logger?->error('Failed to get notification statistics', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}