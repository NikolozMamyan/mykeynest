<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\UserSubscription;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class EmailTemplateRenderTest extends KernelTestCase
{
    public function testSubscriptionEmailsRenderWithBillingSummary(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get(Environment::class);

        $user = (new User())
            ->setEmail('client@example.com')
            ->setCompany('Acme')
            ->setPassword('hash')
            ->setLocale('fr');
        $subscription = (new UserSubscription())
            ->setUser($user)
            ->setPlanCode('team')
            ->setQuantity(8)
            ->setStatus('active')
            ->setIsActive(true)
            ->setCurrentPeriodEnd(new \DateTimeImmutable('2026-09-20 12:00:00'));
        $user->setUserSubscription($subscription);

        $html = $twig->render('emails/pro_checkout_existing_user.html.twig', [
            'user' => $user,
            'subscription' => $subscription,
            'planName' => 'TEAM',
            'quantity' => 8,
            'monthlyAmount' => '43,92 €',
            'login_url' => 'https://key-nest.com/login',
            'forgot_password_url' => 'https://key-nest.com/forgot-password',
            'locale' => 'fr',
        ]);

        self::assertStringContainsString('Paiement confirmé', $html);
        self::assertStringContainsString('43,92 €', $html);
        self::assertStringContainsString('20/09/2026', $html);
        self::assertStringContainsString('MYKEYNEST TEAM', $html);
    }

    public function testCancellationEmailConfirmsAccessEndDate(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get(Environment::class);

        $subscription = (new UserSubscription())
            ->setPlanCode('pro')
            ->setCurrentPeriodEnd(new \DateTimeImmutable('2026-09-20 12:00:00'));

        $html = $twig->render('emails/subscription_status.html.twig', [
            'subscription' => $subscription,
            'cancelAtPeriodEnd' => true,
            'subscription_url' => 'https://key-nest.com/app/subscription',
            'locale' => 'fr',
        ]);

        self::assertStringContainsString('Votre abonnement prendra fin le 20/09/2026', $html);
        self::assertStringContainsString('aucun nouveau mois ne sera facturé', $html);
    }
}
