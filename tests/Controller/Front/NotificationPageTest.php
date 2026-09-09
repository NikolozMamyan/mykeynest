<?php

namespace App\Tests\Controller\Front;

use App\Controller\Front\NotificationController;
use App\Entity\Notification;
use App\Entity\User;
use App\Service\NotificationService;
use App\Service\SubscriptionPlanService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class NotificationPageTest extends KernelTestCase
{
    public function testAuthenticatedUserCanRenderTheEmptyNotificationCenter(): void
    {
        [$controller] = $this->prepareController();

        $notifications = $this->createMock(NotificationService::class);
        $notifications->expects($this->once())->method('getRecentNotifications')->willReturn([]);
        $notifications->expects($this->once())->method('getUnreadCount')->willReturn(0);
        $notifications->expects($this->never())->method('markAllAsRead');

        $response = $controller->index($notifications);
        $crawler = new Crawler((string) $response->getContent());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Vos notifications', $crawler->filter('.notification-center h1')->text());
        self::assertSame(1, $crawler->filter('.notification-center__empty')->count());
        self::assertSame(1, $crawler->filter('a.notification-panel-footer[data-turbo="true"]')->count());
    }

    public function testUnreadNotificationIsRenderedAndMarkedAsRead(): void
    {
        [$controller, $user] = $this->prepareController();
        $notification = (new Notification())
            ->setUser($user)
            ->setTitle('Partage disponible')
            ->setMessage('Un nouvel accès a été partagé avec vous.')
            ->setType(Notification::TYPE_SUCCESS)
            ->setActionUrl('/app/credential')
            ->setIcon('fa-solid fa-key')
            ->setPriority(Notification::PRIORITY_HIGH);
        $id = new \ReflectionProperty(Notification::class, 'id');
        $id->setValue($notification, 12);

        $notifications = $this->createMock(NotificationService::class);
        $notifications->expects($this->once())->method('getRecentNotifications')->willReturn([$notification]);
        $notifications->expects($this->once())->method('getUnreadCount')->willReturn(1);
        $notifications->expects($this->once())->method('markAllAsRead')->with($this->identicalTo($user));

        $response = $controller->index($notifications);
        $crawler = new Crawler((string) $response->getContent());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $crawler->filter('.notification-center__item.is-new')->count());
        self::assertSame(1, $crawler->filter('a.notification-center__open[data-turbo="true"][href="/app/credential"]')->count());
        self::assertSame(1, $crawler->filter('form[action="/app/notifications/12/delete"] input[name="_token"]')->count());
    }

    /** @return array{NotificationController, User} */
    private function prepareController(): array
    {
        self::bootKernel();
        $container = static::getContainer();
        $user = (new User())
            ->setEmail('notifications@example.test')
            ->setPassword('hashed')
            ->setCompany('MYKEYNEST');

        $request = Request::create('/app/notifications');
        $request->attributes->set('_route', 'app_notifications');
        $request->setLocale('fr');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);
        $container->get('translator')->setLocale('fr');
        $container->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
        );
        $resolvedPlans = new \ReflectionProperty(SubscriptionPlanService::class, 'resolvedPlans');
        $resolvedPlans->setValue($container->get(SubscriptionPlanService::class), [
            SubscriptionPlanService::PLAN_FREE => [
                'code' => SubscriptionPlanService::PLAN_FREE,
                'label' => 'Free',
                'limits' => [],
                'features' => [],
                'editable' => true,
                'updatedAt' => null,
            ],
        ]);

        return [$container->get(NotificationController::class), $user];
    }
}
