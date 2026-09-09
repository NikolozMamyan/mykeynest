<?php

namespace App\Tests\Service;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class NotificationServiceCacheTest extends TestCase
{
    public function testUnreadCountIsCachedAndInvalidatedWhenNotificationIsCreated(): void
    {
        $user = $this->createUser(84);
        $repository = $this->createMock(NotificationRepository::class);
        $repository
            ->expects($this->exactly(2))
            ->method('countUnreadByUser')
            ->with($this->identicalTo($user))
            ->willReturnOnConsecutiveCalls(2, 3);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(Notification::class));
        $entityManager->expects($this->once())->method('flush');

        $service = new NotificationService($entityManager, $repository, new ArrayAdapter());

        self::assertSame(2, $service->getUnreadCount($user));
        self::assertSame(2, $service->getUnreadCount($user));

        $service->createNotification($user, 'Nouvelle notification');

        self::assertSame(3, $service->getUnreadCount($user));
    }

    private function createUser(int $id): User
    {
        $user = (new User())
            ->setEmail('notification-'.$id.'@example.test')
            ->setPassword('hashed')
            ->setCompany('MYKEYNEST');

        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);

        return $user;
    }
}
