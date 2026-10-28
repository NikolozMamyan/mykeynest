<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\UserSubscription;
use App\Service\MailerService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

final class EmailTemplateRenderTest extends KernelTestCase
{
    /** @dataProvider emailTemplates */
    public function testTransactionalTemplatesRenderAsLightweightStandaloneEmails(string $template, array $context): void
    {
        self::bootKernel();
        self::getContainer()->get('translator')->setLocale($context['locale']);
        $html = self::getContainer()->get(Environment::class)->render($template, $context);
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);

        self::assertSame($context['locale'], $document->documentElement->getAttribute('lang'));
        self::assertSame(1, $document->getElementsByTagName('h1')->length);
        self::assertSame(0, $xpath->query('//script | //iframe | //form | //link[@rel="stylesheet"]')->length);
        self::assertLessThan(32768, strlen($html));
        self::assertStringContainsString('contact@key-nest.com', $document->textContent);
        self::assertStringContainsString('OptiWebSolutions', $document->textContent);
        self::assertStringNotContainsString('link_fallback', $document->textContent);
        foreach ($document->getElementsByTagName('img') as $image) {
            self::assertStringStartsWith('https://key-nest.com/', $image->getAttribute('src'));
            self::assertTrue($image->hasAttribute('alt'));
            self::assertTrue($image->hasAttribute('width'));
            self::assertTrue($image->hasAttribute('height'));
        }
        foreach ($document->getElementsByTagName('a') as $link) {
            self::assertMatchesRegularExpression('~^(https?://|mailto:)~', $link->getAttribute('href'));
            self::assertNotSame('', trim($link->textContent));
        }
    }

    public static function emailTemplates(): iterable
    {
        $date = new \DateTimeImmutable('2026-10-28 10:00:00+01:00');
        $base = [
            'locale' => 'fr',
            'user' => ['email' => 'camille@example.invalid', 'company' => 'Atelier Nova'],
            'subscription' => ['planCode' => 'team', 'quantity' => 8, 'status' => 'active', 'currentPeriodEnd' => $date],
            'expiresAt' => $date,
        ];
        $urls = [
            'settingsUrl' => 'https://key-nest.com/app/settings',
            'adminUrl' => 'https://key-nest.com/admin',
            'guest_register' => 'https://key-nest.com/guest/register?token=preview-token',
            'guest_preview' => 'https://key-nest.com/guest/shared-preview?token=preview-token',
            'action_url' => 'https://key-nest.com/guest/register?token=preview-token',
            'app_url' => 'https://key-nest.com/app/shared-access',
            'login_url' => 'https://key-nest.com/login',
            'forgot_password_url' => 'https://key-nest.com/reset-password',
            'setup_url' => 'https://key-nest.com/register?token=preview-token',
            'subscription_url' => 'https://key-nest.com/app/subscription',
        ];
        yield 'welcome FR' => ['emails/welcome.html.twig', $base + $urls];
        yield 'welcome EN' => ['emails/welcome.html.twig', array_replace($base + $urls, ['locale' => 'en'])];
        yield 'guest registration' => ['emails/register_guest.html.twig', $base + $urls];
        yield 'team invitation' => ['emails/team_invitation.html.twig', $base + $urls + ['team' => ['name' => 'Équipe produit']]];
        foreach ([true, false] as $registration) {
            yield 'organization '.($registration ? 'guest' : 'member') => ['emails/organization_invitation.html.twig', $base + $urls + [
                'organization' => ['name' => 'Atelier Nova'],
                'membership' => ['role' => ['value' => 'MEMBER']],
                'requires_registration' => $registration,
            ]];
        }
        yield 'shared access' => ['emails/share_notification.html.twig', $base + $urls + [
            'owner' => ['email' => 'alex@example.invalid'],
            'credentials' => [
                ['name' => 'Portail client', 'domain' => 'portal.example.invalid', 'username' => 'camille@example.invalid'],
                ['name' => 'Espace projet', 'domain' => 'project.example.invalid', 'username' => 'team@example.invalid'],
            ],
        ]];
        yield 'subscription activation' => ['emails/pro_checkout_activation.html.twig', $base + $urls + ['planName' => 'TEAM', 'quantity' => 8, 'monthlyAmount' => '43,92 €']];
        yield 'subscription existing account' => ['emails/pro_checkout_existing_user.html.twig', $base + $urls + ['planName' => 'TEAM', 'quantity' => 8, 'monthlyAmount' => '43,92 €']];
        foreach ([true, false] as $cancelled) {
            yield 'subscription '.($cancelled ? 'cancelled' : 'resumed') => ['emails/subscription_status.html.twig', $base + $urls + ['cancelAtPeriodEnd' => $cancelled]];
        }
        $security = $base + [
            'approveUrl' => 'https://key-nest.com/api/login-challenge/preview/approve?token='.str_repeat('a', 128),
            'rejectUrl' => 'https://key-nest.com/api/login-challenge/preview/reject?token='.str_repeat('b', 128),
            'ip' => '192.0.2.10',
            'userAgent' => 'Chrome on Windows '.str_repeat('long-device-label', 12),
            'requestedAt' => $date,
            'deviceLabel' => 'Ordinateur de Camille',
            'browserName' => 'Chrome', 'browserVersion' => '130',
            'osName' => 'Windows', 'osVersion' => '11',
        ];
        yield 'login confirmation' => ['emails/security/login_challenge.html.twig', $security];
        yield 'extension confirmation' => ['emails/security/extension_installation_challenge.html.twig', $security];
        yield 'admin registration' => ['emails/admin_new_registration.html.twig', $base + $urls + ['registeredAt' => $date, 'ip' => '192.0.2.10', 'userAgent' => 'Chrome on Windows']];
        yield 'admin payment' => ['emails/admin_subscription_paid.html.twig', $base + $urls + ['paidAt' => $date, 'source' => 'stripe']];
        yield 'admin support' => ['emails/admin_support_chat_message.html.twig', $base + $urls + ['conversation' => ['id' => 12, 'email' => 'camille@example.invalid'], 'receivedAt' => $date, 'message' => "Bonjour,\nJe souhaite des précisions."]];
        yield 'contact' => ['emails/contact.html.twig', $base + ['name' => 'Camille', 'userEmail' => 'camille@example.invalid', 'reasonLabel' => 'Question produit', 'message' => "Bonjour,\nJe souhaite une démonstration."]];
        foreach (['fr', 'en'] as $locale) {
            yield 'password reset '.$locale => ['reset_password/email.html.twig', [
                'locale' => $locale,
                'resetToken' => ['token' => 'preview-reset-token', 'expirationMessageKey' => '%count% hour|%count% hours', 'expirationMessageData' => ['%count%' => 1]],
            ]];
        }
    }

    public function testHtmlActionsKeepBothSecurityUrlsInTheGeneratedTextAlternative(): void
    {
        self::bootKernel();
        $cases = iterator_to_array(self::emailTemplates());
        [$template, $context] = $cases['login confirmation'];
        $captured = null;
        $transport = $this->createMock(MailerInterface::class);
        $transport->expects(self::once())->method('send')->willReturnCallback(static function ($email) use (&$captured): void {
            $captured = $email;
        });
        $mailer = new MailerService($transport, self::getContainer()->get(Environment::class));
        $mailer->send('preview@example.invalid', 'Confirmation de connexion', $template, $context);

        self::assertInstanceOf(Email::class, $captured);
        self::assertStringContainsString($context['approveUrl'], $captured->getTextBody());
        self::assertStringContainsString($context['rejectUrl'], $captured->getTextBody());
        self::assertStringNotContainsString('<style', $captured->getTextBody());
    }

    public function testWelcomeEscapesRecipientDataInsideFormattedTranslations(): void
    {
        self::bootKernel();
        $html = self::getContainer()->get(Environment::class)->render('emails/welcome.html.twig', [
            'user' => ['email' => '<img src=x onerror=alert(1)>'],
            'settingsUrl' => 'https://key-nest.com/app/settings',
        ]);

        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;img src=x', $html);
    }

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
