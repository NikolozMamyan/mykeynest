<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\UserSubscription;
use App\Repository\UserRepository;
use App\Repository\UserSubscriptionRepository;
use App\Repository\StripePaymentConfigurationRepository;
use App\Service\AdminNotificationService;
use App\Service\MailerService;
use App\Service\StripeBillingService;
use App\Service\StripeEnvironmentManager;
use App\Service\StripePlanCatalog;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stripe\Event;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class StripeBillingServiceTest extends TestCase
{
    public function testDeletedSubscriptionRevokesAccessButKeepsStripeAssociation(): void
    {
        $user = (new User())
            ->setEmail('owner@example.com')
            ->setCompany('Acme')
            ->setPassword('hashed');
        $record = (new UserSubscription())
            ->setUser($user)
            ->setStripeCustomerId('cus_team')
            ->setStripeSubscriptionId('sub_team')
            ->setPlanCode('team')
            ->setStatus('active')
            ->setIsActive(true);
        $user->setUserSubscription($record);

        $subscriptions = $this->createMock(UserSubscriptionRepository::class);
        $subscriptions
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['stripeSubscriptionId' => 'sub_team', 'stripeMode' => 'sandbox'])
            ->willReturn($record);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $service = $this->createService($subscriptions, $entityManager);

        $event = Event::constructFrom([
            'id' => 'evt_deleted',
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => 'sub_team',
                    'object' => 'subscription',
                    'customer' => 'cus_team',
                    'status' => 'canceled',
                    'cancel_at_period_end' => false,
                    'metadata' => ['plan' => 'team'],
                    'items' => [
                        'object' => 'list',
                        'data' => [[
                            'id' => 'si_team',
                            'object' => 'subscription_item',
                            'quantity' => 6,
                            'current_period_end' => 1789905600,
                            'price' => ['id' => 'price_team', 'object' => 'price'],
                        ]],
                    ],
                ],
            ],
        ]);

        self::assertTrue($service->processWebhookEvent($event, 'sandbox'));
        self::assertFalse($record->isActive());
        self::assertSame('canceled', $record->getStatus());
        self::assertSame('team', $record->getPlanCode());
        self::assertSame('sub_team', $record->getStripeSubscriptionId());
        self::assertSame('price_team', $record->getStripePriceId());
        self::assertSame(6, $record->getQuantity());
        self::assertNotNull($record->getCurrentPeriodEnd());
    }

    public function testLateEventCannotReplaceAnotherActiveSubscriptionForSameCustomer(): void
    {
        $user = (new User())
            ->setEmail('owner@example.com')
            ->setCompany('Acme')
            ->setPassword('hashed');
        $activeRecord = (new UserSubscription())
            ->setUser($user)
            ->setStripeCustomerId('cus_team')
            ->setStripeSubscriptionId('sub_current')
            ->setStripeMode('sandbox')
            ->setPlanCode('team')
            ->setStatus('active')
            ->setIsActive(true);
        $user->setUserSubscription($activeRecord);

        $subscriptions = $this->createMock(UserSubscriptionRepository::class);
        $subscriptions
            ->expects(self::exactly(2))
            ->method('findOneBy')
            ->willReturnCallback(static fn (array $criteria): ?UserSubscription => match ($criteria) {
                ['stripeSubscriptionId' => 'sub_old', 'stripeMode' => 'sandbox'] => null,
                ['stripeCustomerId' => 'cus_team', 'stripeMode' => 'sandbox'] => $activeRecord,
                default => null,
            });
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $service = $this->createService($subscriptions, $entityManager);

        $event = Event::constructFrom([
            'id' => 'evt_old_deleted',
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => 'sub_old',
                    'object' => 'subscription',
                    'customer' => 'cus_team',
                    'status' => 'canceled',
                    'cancel_at_period_end' => false,
                    'metadata' => ['plan' => 'team'],
                    'items' => [
                        'object' => 'list',
                        'data' => [[
                            'id' => 'si_old',
                            'object' => 'subscription_item',
                            'quantity' => 6,
                            'price' => ['id' => 'price_team', 'object' => 'price'],
                        ]],
                    ],
                ],
            ],
        ]);

        self::assertTrue($service->processWebhookEvent($event, 'sandbox'));
        self::assertTrue($activeRecord->isActive());
        self::assertSame('sub_current', $activeRecord->getStripeSubscriptionId());
        self::assertSame('active', $activeRecord->getStatus());
    }

    public function testSandboxEventCannotOverwriteAnActiveLiveSubscription(): void
    {
        $user = (new User())
            ->setEmail('owner@example.com')
            ->setCompany('Acme')
            ->setPassword('hashed');
        $liveRecord = (new UserSubscription())
            ->setUser($user)
            ->setStripeCustomerId('cus_live')
            ->setStripeSubscriptionId('sub_live')
            ->setStripeMode('production')
            ->setPlanCode('team')
            ->setStatus('active')
            ->setIsActive(true);
        $user->setUserSubscription($liveRecord);

        $subscriptions = $this->createMock(UserSubscriptionRepository::class);
        $subscriptions->method('findOneBy')->willReturn(null);
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())->method('find')->with(42)->willReturn($user);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $service = $this->createService($subscriptions, $entityManager, $users);

        $event = Event::constructFrom([
            'id' => 'evt_sandbox_deleted',
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => 'sub_sandbox',
                    'object' => 'subscription',
                    'customer' => 'cus_sandbox',
                    'status' => 'canceled',
                    'metadata' => ['plan' => 'team', 'user_id' => '42'],
                    'items' => [
                        'object' => 'list',
                        'data' => [[
                            'id' => 'si_sandbox',
                            'object' => 'subscription_item',
                            'quantity' => 6,
                            'price' => ['id' => 'price_team', 'object' => 'price'],
                        ]],
                    ],
                ],
            ],
        ]);

        self::assertTrue($service->processWebhookEvent($event, 'sandbox'));
        self::assertTrue($liveRecord->isActive());
        self::assertSame('production', $liveRecord->getStripeMode());
        self::assertSame('sub_live', $liveRecord->getStripeSubscriptionId());
    }

    private function createService(
        UserSubscriptionRepository $subscriptions,
        EntityManagerInterface $entityManager,
        ?UserRepository $users = null,
    ): StripeBillingService {
        $mailer = $this->createMock(MailerService::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $logger = new NullLogger();

        $paymentConfiguration = $this->createMock(StripePaymentConfigurationRepository::class);
        $paymentConfiguration->method('findConfiguration')->willReturn(null);
        $stripeEnvironments = new StripeEnvironmentManager(
            $paymentConfiguration,
            $entityManager,
            'sk_test_fake',
            'price_pro',
            'price_team',
            'whsec_fake',
            '', '', '', '',
        );

        return new StripeBillingService(
            $stripeEnvironments,
            new StripePlanCatalog(6, 250),
            $users ?? $this->createMock(UserRepository::class),
            $subscriptions,
            $entityManager,
            $mailer,
            new AdminNotificationService($mailer, $urlGenerator, $logger),
            $urlGenerator,
            $logger,
            'https://key-nest.com',
        );
    }
}
