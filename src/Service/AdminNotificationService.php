<?php

namespace App\Service;

use App\Entity\SupportConversation;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AdminNotificationService
{
    public function __construct(
        private MailerService $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    public function notifyNewRegistration(User $user, ?string $ip = null, ?string $userAgent = null): void
    {
        $to = $this->getAdminEmail();
        if ($to === null) {
            return;
        }

        $this->mailer->send(
            $to,
            'Nouvelle inscription MYKEYNEST',
            'emails/admin_new_registration.html.twig',
            [
                'user' => $user,
                'registeredAt' => new \DateTimeImmutable(),
                'ip' => $ip,
                'userAgent' => $userAgent,
                'adminUrl' => $this->urlGenerator->generate('app_admin', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]
        );
    }

    public function notifySubscriptionActivated(User $user, string $source = 'stripe'): void
    {
        $to = $this->getAdminEmail();
        if ($to === null) {
            return;
        }

        $subscription = $user->getUserSubscription();

        $this->mailer->send(
            $to,
            'Nouveau paiement abonnement MYKEYNEST',
            'emails/admin_subscription_paid.html.twig',
            [
                'user' => $user,
                'subscription' => $subscription,
                'source' => $source,
                'paidAt' => new \DateTimeImmutable(),
                'adminUrl' => $this->urlGenerator->generate('app_admin', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]
        );
    }

    public function notifySupportChatMessage(SupportConversation $conversation, string $message): void
    {
        $to = $this->getAdminEmail();
        if ($to === null) {
            return;
        }

        $conversationId = $conversation->getId();

        try {
            $this->mailer->send(
                $to,
                'Nouveau message chat MYKEYNEST',
                'emails/admin_support_chat_message.html.twig',
                [
                    'conversation' => $conversation,
                    'message' => $message,
                    'receivedAt' => new \DateTimeImmutable(),
                    'adminUrl' => $this->urlGenerator->generate(
                        'admin_chat',
                        $conversationId ? ['conversation' => $conversationId] : [],
                        UrlGeneratorInterface::ABSOLUTE_URL
                    ),
                ],
                $conversation->getEmail()
            );
        } catch (\Throwable $e) {
            $this->logger->error('Unable to send support chat admin notification.', [
                'conversationId' => $conversationId,
                'email' => $conversation->getEmail(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function getAdminEmail(): ?string
    {
        $email = $_ENV['ADMIN_NOTIFICATION_EMAIL'] ?? $_ENV['MAIL_FROM_ADDRESS'] ?? 'contact@key-nest.com';
        $email = is_string($email) ? trim($email) : '';

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
