<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\UserSubscription;
use App\Repository\UserRepository;
use App\Repository\UserSubscriptionRepository;
use App\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Stripe;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class StripeWebhookController extends AbstractController
{
    #[Route('/stripe/webhook', name: 'stripe_webhook', methods: ['POST'])]
    public function handle(
        Request $request,
        UserRepository $users,
        UserSubscriptionRepository $subscriptions,
        EntityManagerInterface $em,
        LoggerInterface $logger,
        MailerService $mailer,
        UrlGeneratorInterface $urlGenerator
    ): Response {
        Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

        $payload = $request->getContent();
        $sigHeader = $request->headers->get('Stripe-Signature');

        if (!$sigHeader) {
            $logger->warning('Stripe webhook: missing Stripe-Signature header');

            return new Response('Missing signature', 400);
        }

        try {
            $event = Webhook::constructEvent(
                $payload,
                $sigHeader,
                $_ENV['STRIPE_WEBHOOK_SECRET']
            );
        } catch (\Throwable $e) {
            $logger->error('Stripe webhook: invalid signature or payload', [
                'message' => $e->getMessage(),
            ]);

            return new Response('Invalid signature', 400);
        }

        $logger->info('Stripe webhook received', [
            'type' => $event->type,
            'event_id' => $event->id ?? null,
        ]);

        try {
            switch ($event->type) {
                case 'checkout.session.completed': {
                    /** @var \Stripe\Checkout\Session $session */
                    $session = $event->data->object;

                    $userId = $session->client_reference_id ?? null;
                    $customerId = is_string($session->customer) ? $session->customer : ($session->customer->id ?? null);
                    $subscriptionId = is_string($session->subscription) ? $session->subscription : ($session->subscription->id ?? null);
                    $email = $session->customer_email ?? ($session->customer_details->email ?? null);

                    $logger->info('checkout.session.completed payload', [
                        'client_reference_id' => $userId,
                        'customer_id' => $customerId,
                        'subscription_id' => $subscriptionId,
                        'email' => $email,
                    ]);

                    $user = null;

                    if ($userId) {
                        $user = $users->find((int) $userId);
                    }

                    if (!$user && is_string($email) && $email !== '') {
                        $user = $users->findOneBy(['email' => $email]);
                    }

                    if (!$user) {
                        $user = $this->createPendingCheckoutUser(
                            email: is_string($email) ? $email : null,
                            em: $em,
                            logger: $logger
                        );
                    }

                    if (!$user) {
                        $logger->warning('Stripe webhook: unable to resolve user for checkout.session.completed', [
                            'client_reference_id' => $userId,
                            'email' => $email,
                        ]);
                        break;
                    }

                    $this->syncSubscriptionRecord(
                        user: $user,
                        subscriptions: $subscriptions,
                        em: $em,
                        customerId: $customerId,
                        subscriptionId: $subscriptionId,
                        status: 'pending',
                        isActive: false,
                        planCode: (string) ($session->metadata->plan ?? 'pro')
                    );

                    $em->flush();

                    $logger->info('Stripe webhook: subscription record created/updated after checkout.session.completed', [
                        'user_id' => $user->getId(),
                        'customer_id' => $customerId,
                        'subscription_id' => $subscriptionId,
                    ]);

                    break;
                }

                case 'invoice.payment_succeeded': {
                    /** @var \Stripe\Invoice $invoice */
                    $invoice = $event->data->object;

                    $subscriptionId = is_string($invoice->subscription) ? $invoice->subscription : ($invoice->subscription->id ?? null);
                    $customerId = is_string($invoice->customer) ? $invoice->customer : ($invoice->customer->id ?? null);
                    $email = $invoice->customer_email ?? ($invoice->customer_details->email ?? null);

                    $logger->info('invoice.payment_succeeded payload', [
                        'subscription_id' => $subscriptionId,
                        'customer_id' => $customerId,
                        'email' => $email,
                    ]);

                    $user = $this->findUserFromStripeData(
                        users: $users,
                        subscriptions: $subscriptions,
                        subscriptionId: $subscriptionId,
                        customerId: $customerId,
                        email: is_string($email) ? $email : null
                    );

                    if (!$user) {
                        $logger->warning('Stripe webhook: user not found for invoice.payment_succeeded', [
                            'subscription_id' => $subscriptionId,
                            'customer_id' => $customerId,
                            'email' => $email,
                        ]);
                        break;
                    }

                    $existingSubscription = $user->getUserSubscription() ?? $subscriptions->findOneBy(['user' => $user]);
                    $wasActive = $existingSubscription?->isActive() ?? false;

                    $this->syncSubscriptionRecord(
                        user: $user,
                        subscriptions: $subscriptions,
                        em: $em,
                        customerId: $customerId,
                        subscriptionId: $subscriptionId,
                        status: 'active',
                        isActive: true,
                        planCode: 'pro'
                    );

                    $em->flush();

                    if (!$wasActive) {
                        $this->sendPostPaymentEmail($user, $mailer, $urlGenerator, $logger);
                        $em->flush();
                    }

                    $logger->info('Stripe webhook: subscription activated', [
                        'user_id' => $user->getId(),
                        'customer_id' => $customerId,
                        'subscription_id' => $subscriptionId,
                    ]);

                    break;
                }

                case 'invoice.payment_failed': {
                    /** @var \Stripe\Invoice $invoice */
                    $invoice = $event->data->object;

                    $subscriptionId = is_string($invoice->subscription) ? $invoice->subscription : ($invoice->subscription->id ?? null);
                    $customerId = is_string($invoice->customer) ? $invoice->customer : ($invoice->customer->id ?? null);
                    $email = $invoice->customer_email ?? ($invoice->customer_details->email ?? null);

                    $user = $this->findUserFromStripeData(
                        users: $users,
                        subscriptions: $subscriptions,
                        subscriptionId: $subscriptionId,
                        customerId: $customerId,
                        email: is_string($email) ? $email : null
                    );

                    if (!$user) {
                        $logger->warning('Stripe webhook: user not found for invoice.payment_failed', [
                            'subscription_id' => $subscriptionId,
                            'customer_id' => $customerId,
                            'email' => $email,
                        ]);
                        break;
                    }

                    $this->syncSubscriptionRecord(
                        user: $user,
                        subscriptions: $subscriptions,
                        em: $em,
                        customerId: $customerId,
                        subscriptionId: $subscriptionId,
                        status: 'past_due',
                        isActive: false
                    );

                    $em->flush();

                    $logger->info('Stripe webhook: subscription marked past_due', [
                        'user_id' => $user->getId(),
                        'subscription_id' => $subscriptionId,
                    ]);

                    break;
                }

                case 'customer.subscription.updated': {
                    /** @var \Stripe\Subscription $sub */
                    $sub = $event->data->object;

                    $subscriptionId = $sub->id ?? null;
                    $customerId = is_string($sub->customer) ? $sub->customer : ($sub->customer->id ?? null);
                    $status = $sub->status ?? null;

                    if (!$subscriptionId || !is_string($status)) {
                        $logger->warning('Stripe webhook: invalid customer.subscription.updated payload', [
                            'subscription_id' => $subscriptionId,
                            'status' => $status,
                        ]);
                        break;
                    }

                    $user = $this->findUserFromStripeData(
                        users: $users,
                        subscriptions: $subscriptions,
                        subscriptionId: $subscriptionId,
                        customerId: $customerId
                    );

                    if (!$user) {
                        $logger->warning('Stripe webhook: user not found for customer.subscription.updated', [
                            'subscription_id' => $subscriptionId,
                            'customer_id' => $customerId,
                            'status' => $status,
                        ]);
                        break;
                    }

                    $isActive = \in_array($status, ['active', 'trialing'], true);

                    $this->syncSubscriptionRecord(
                        user: $user,
                        subscriptions: $subscriptions,
                        em: $em,
                        customerId: $customerId,
                        subscriptionId: $subscriptionId,
                        status: $status,
                        isActive: $isActive
                    );

                    $em->flush();

                    $logger->info('Stripe webhook: subscription updated', [
                        'user_id' => $user->getId(),
                        'subscription_id' => $subscriptionId,
                        'status' => $status,
                        'is_active' => $isActive,
                    ]);

                    break;
                }

                case 'customer.subscription.deleted': {
                    /** @var \Stripe\Subscription $sub */
                    $sub = $event->data->object;

                    $subscriptionId = $sub->id ?? null;
                    $customerId = is_string($sub->customer) ? $sub->customer : ($sub->customer->id ?? null);

                    if (!$subscriptionId) {
                        $logger->warning('Stripe webhook: missing subscription id for customer.subscription.deleted');
                        break;
                    }

                    $user = $this->findUserFromStripeData(
                        users: $users,
                        subscriptions: $subscriptions,
                        subscriptionId: $subscriptionId,
                        customerId: $customerId
                    );

                    if (!$user) {
                        $logger->warning('Stripe webhook: user not found for customer.subscription.deleted', [
                            'subscription_id' => $subscriptionId,
                            'customer_id' => $customerId,
                        ]);
                        break;
                    }

                    $this->syncSubscriptionRecord(
                        user: $user,
                        subscriptions: $subscriptions,
                        em: $em,
                        customerId: $customerId,
                        subscriptionId: null,
                        status: 'canceled',
                        isActive: false,
                        clearSubscriptionId: true
                    );

                    $em->flush();

                    $logger->info('Stripe webhook: subscription canceled', [
                        'user_id' => $user->getId(),
                        'old_subscription_id' => $subscriptionId,
                    ]);

                    break;
                }

                default:
                    $logger->info('Stripe webhook: event ignored', [
                        'type' => $event->type,
                    ]);
                    break;
            }
        } catch (\Throwable $e) {
            $logger->error('Stripe webhook processing failed', [
                'type' => $event->type ?? null,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return new Response('Webhook error: ' . $e->getMessage(), 500);
        }

        return new Response('ok', 200);
    }

    private function findUserFromStripeData(
        UserRepository $users,
        UserSubscriptionRepository $subscriptions,
        ?string $subscriptionId = null,
        ?string $customerId = null,
        ?string $email = null
    ): ?User {
        if ($subscriptionId) {
            $subscription = $subscriptions->findOneBy(['stripeSubscriptionId' => $subscriptionId]);
            if ($subscription?->getUser()) {
                return $subscription->getUser();
            }
        }

        if ($customerId) {
            $subscription = $subscriptions->findOneBy(['stripeCustomerId' => $customerId]);
            if ($subscription?->getUser()) {
                return $subscription->getUser();
            }
        }

        if ($email) {
            return $users->findOneBy(['email' => $email]);
        }

        return null;
    }

    private function syncSubscriptionRecord(
        User $user,
        UserSubscriptionRepository $subscriptions,
        EntityManagerInterface $em,
        ?string $customerId = null,
        ?string $subscriptionId = null,
        ?string $status = null,
        ?bool $isActive = null,
        ?string $planCode = null,
        bool $clearSubscriptionId = false
    ): UserSubscription {
        $subscription = $user->getUserSubscription();

        if (!$subscription) {
            $subscription = $subscriptions->findOneBy(['user' => $user]);
        }

        if (!$subscription) {
            $subscription = new UserSubscription();
            $subscription->setUser($user);
            $user->setUserSubscription($subscription);
            $em->persist($subscription);
        }

        if ($customerId !== null) {
            $subscription->setStripeCustomerId($customerId);
        }

        if ($clearSubscriptionId) {
            $subscription->setStripeSubscriptionId(null);
        } elseif ($subscriptionId !== null) {
            $subscription->setStripeSubscriptionId($subscriptionId);
        }

        if ($status !== null) {
            $subscription->setStatus($status);
        }

        if ($isActive !== null) {
            $subscription->setIsActive($isActive);
        }

        if ($planCode !== null) {
            $subscription->setPlanCode($planCode);
        }

        $subscription->touch();

        return $subscription;
    }

    private function createPendingCheckoutUser(
        ?string $email,
        EntityManagerInterface $em,
        LoggerInterface $logger
    ): ?User {
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $logger->warning('Stripe webhook: cannot create pending user without valid email', [
                'email' => $email,
            ]);

            return null;
        }

        $user = new User();
        $user->setEmail(strtolower(trim($email)));
        $user->setCompany('');
        $user->setPassword('');
        $user->setRoles(['ROLE_GUEST']);
        $user->setApiToken(bin2hex(random_bytes(32)));
        $user->setTokenExpiresAt(new \DateTimeImmutable('+7 days'));
        $user->regenerateApiExtensionToken();

        $em->persist($user);

        return $user;
    }

    private function sendPostPaymentEmail(
        User $user,
        MailerService $mailer,
        UrlGeneratorInterface $urlGenerator,
        LoggerInterface $logger
    ): void {
        try {
            if (in_array('ROLE_GUEST', $user->getRoles(), true) && $user->getApiToken()) {
                $expiresAt = new \DateTimeImmutable('+7 days');
                $user->setApiToken(bin2hex(random_bytes(32)));
                $user->setTokenExpiresAt($expiresAt);

                $setupUrl = $urlGenerator->generate('app_guest_register', [
                    'token' => $user->getApiToken(),
                    'email' => $user->getEmail(),
                ], UrlGeneratorInterface::ABSOLUTE_URL);

                $mailer->send(
                    (string) $user->getEmail(),
                    'Votre abonnement Pro est actif',
                    'emails/pro_checkout_activation.html.twig',
                    [
                        'user' => $user,
                        'setup_url' => $setupUrl,
                        'expiresAt' => $expiresAt,
                    ]
                );

                return;
            }

            $loginUrl = $urlGenerator->generate('show_login', [], UrlGeneratorInterface::ABSOLUTE_URL);
            $forgotPasswordUrl = $urlGenerator->generate('app_forgot_password_request', [], UrlGeneratorInterface::ABSOLUTE_URL);

            $mailer->send(
                (string) $user->getEmail(),
                'Votre abonnement Pro est actif',
                'emails/pro_checkout_existing_user.html.twig',
                [
                    'user' => $user,
                    'login_url' => $loginUrl,
                    'forgot_password_url' => $forgotPasswordUrl,
                ]
            );
        } catch (\Throwable $e) {
            $logger->error('Stripe webhook: failed to send post-payment email', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail(),
                'message' => $e->getMessage(),
            ]);
        }
    }
}
