<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\UserSubscription;
use App\Repository\UserSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Stripe;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StripeWebhookController extends AbstractController
{
    #[Route('/stripe/webhook', name: 'stripe_webhook', methods: ['POST'])]
    public function handle(
        Request $request,
        UserSubscriptionRepository $subscriptions,
        EntityManagerInterface $em,
        LoggerInterface $logger
    ): Response {
        Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

        $payload = $request->getContent();
        $sigHeader = $request->headers->get('Stripe-Signature');

        try {
            $event = Webhook::constructEvent(
                $payload,
                $sigHeader,
                $_ENV['STRIPE_WEBHOOK_SECRET']
            );
        } catch (\Throwable $e) {
            $logger->error('Stripe webhook signature validation failed.', [
                'message' => $e->getMessage(),
                'path' => $request->getPathInfo(),
                'has_signature_header' => $sigHeader !== null,
                'payload_length' => strlen($payload),
            ]);

            return new Response('Invalid signature', 400);
        }

        $logger->info('Stripe webhook received.', [
            'event_type' => $event->type,
            'event_id' => $event->id ?? null,
        ]);

        switch ($event->type) {
            case 'checkout.session.completed': {
                /** @var \Stripe\Checkout\Session $session */
                $session = $event->data->object;

                $user = $this->resolveUserFromCheckoutSession($session, $em);
                if (!$user) {
                    $logger->warning('Stripe checkout.session.completed skipped: user not found.', [
                        'event_id' => $event->id ?? null,
                        'session_id' => $session->id ?? null,
                        'client_reference_id' => $session->client_reference_id ?? null,
                        'metadata_user_id' => $session->metadata->user_id ?? null,
                        'customer_email' => $session->customer_email ?? ($session->customer_details->email ?? null),
                    ]);
                    break;
                }

                $this->syncSubscriptionRecord(
                    $user,
                    $em,
                    customerId: $this->extractStripeId($session->customer),
                    subscriptionId: $this->extractStripeId($session->subscription),
                    status: $session->payment_status === 'paid' ? 'active' : null,
                    isActive: $session->payment_status === 'paid',
                    planCode: (string) ($session->metadata->plan ?? 'pro')
                );

                $em->flush();

                $logger->info('User subscription synced from checkout.session.completed.', [
                    'event_id' => $event->id ?? null,
                    'user_id' => $user->getId(),
                    'session_id' => $session->id ?? null,
                    'customer_id' => $this->extractStripeId($session->customer),
                    'subscription_id' => $this->extractStripeId($session->subscription),
                ]);
                break;
            }

            case 'invoice.payment_succeeded':
            case 'invoice.paid': {
                /** @var \Stripe\Invoice $invoice */
                $invoice = $event->data->object;

                $this->handlePaidInvoice($invoice, $event->id ?? null, $subscriptions, $em, $logger);
                break;
            }

            case 'customer.subscription.deleted': {
                /** @var \Stripe\Subscription $sub */
                $sub = $event->data->object;

                $subId = $sub->id ?? null;
                if (!$subId) {
                    break;
                }

                $user = $subscriptions->findOneBy(['stripeSubscriptionId' => $subId])?->getUser();
                if (!$user) {
                    $logger->warning('Stripe customer.subscription.deleted skipped: subscription owner not found.', [
                        'event_id' => $event->id ?? null,
                        'subscription_id' => $subId,
                    ]);
                    break;
                }

                $this->syncSubscriptionRecord(
                    $user,
                    $em,
                    status: 'canceled',
                    isActive: false,
                    clearSubscriptionId: true
                );

                $em->flush();

                $logger->info('User subscription marked inactive from customer.subscription.deleted.', [
                    'event_id' => $event->id ?? null,
                    'user_id' => $user->getId(),
                    'subscription_id' => $subId,
                ]);
                break;
            }

            case 'customer.subscription.updated': {
                /** @var \Stripe\Subscription $sub */
                $sub = $event->data->object;

                $subId = $sub->id ?? null;
                if (!$subId) {
                    break;
                }

                $user = $subscriptions->findOneBy(['stripeSubscriptionId' => $subId])?->getUser();
                if (!$user) {
                    $logger->warning('Stripe customer.subscription.updated skipped: subscription owner not found.', [
                        'event_id' => $event->id ?? null,
                        'subscription_id' => $subId,
                    ]);
                    break;
                }

                $status = $sub->status ?? null;
                if (!is_string($status)) {
                    break;
                }

                $this->syncSubscriptionRecord(
                    $user,
                    $em,
                    customerId: $this->extractStripeId($sub->customer),
                    subscriptionId: $subId,
                    status: $status,
                    isActive: in_array($status, ['active', 'trialing'], true)
                );

                $em->flush();

                $logger->info('User subscription updated from customer.subscription.updated.', [
                    'event_id' => $event->id ?? null,
                    'user_id' => $user->getId(),
                    'subscription_id' => $subId,
                    'status' => $status,
                ]);
                break;
            }

            default:
                $logger->info('Stripe webhook event ignored.', [
                    'event_type' => $event->type,
                    'event_id' => $event->id ?? null,
                ]);
                break;
        }

        return new Response('ok', 200);
    }

    private function handlePaidInvoice(
        \Stripe\Invoice $invoice,
        ?string $eventId,
        UserSubscriptionRepository $subscriptions,
        EntityManagerInterface $em,
        LoggerInterface $logger
    ): void {
        $subId = $this->extractInvoiceSubscriptionId($invoice);
        $customerId = $this->extractStripeId($invoice->customer);
        $email = $invoice->customer_email ?? ($invoice->customer_details->email ?? null);

        $user = null;

        if ($subId) {
            $user = $subscriptions->findOneBy(['stripeSubscriptionId' => $subId])?->getUser();
        }

        if (!$user && $customerId) {
            $user = $subscriptions->findOneBy(['stripeCustomerId' => $customerId])?->getUser();
        }

        if (!$user && is_string($email) && $email !== '') {
            /** @var User|null $user */
            $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        }

        if (!$user) {
            $logger->warning('Stripe paid invoice skipped: user not found.', [
                'event_id' => $eventId,
                'customer_id' => $customerId,
                'subscription_id' => $subId,
                'customer_email' => $email,
            ]);

            return;
        }

        $this->syncSubscriptionRecord(
            $user,
            $em,
            customerId: $customerId,
            subscriptionId: $subId,
            status: 'active',
            isActive: true,
            planCode: 'pro'
        );

        $em->flush();

        $logger->info('User subscription activated from paid invoice event.', [
            'event_id' => $eventId,
            'user_id' => $user->getId(),
            'customer_id' => $customerId,
            'subscription_id' => $subId,
        ]);
    }

    private function resolveUserFromCheckoutSession(
        \Stripe\Checkout\Session $session,
        EntityManagerInterface $em
    ): ?User {
        $userId = $session->client_reference_id ?? ($session->metadata->user_id ?? null);

        if (is_string($userId) && ctype_digit($userId)) {
            $user = $em->getRepository(User::class)->find((int) $userId);
            if ($user instanceof User) {
                return $user;
            }
        }

        $email = $session->customer_email ?? ($session->customer_details->email ?? null);
        if (is_string($email) && $email !== '') {
            $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($user instanceof User) {
                return $user;
            }
        }

        return null;
    }

    private function extractInvoiceSubscriptionId(\Stripe\Invoice $invoice): ?string
    {
        $directSubscriptionId = $this->extractStripeId($invoice->subscription ?? null);
        if ($directSubscriptionId !== null) {
            return $directSubscriptionId;
        }

        $parentSubscriptionId = $invoice->parent->subscription_details->subscription ?? null;

        return is_string($parentSubscriptionId) && $parentSubscriptionId !== ''
            ? $parentSubscriptionId
            : null;
    }

    private function extractStripeId(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_object($value) && isset($value->id) && is_string($value->id) && $value->id !== '') {
            return $value->id;
        }

        return null;
    }

    private function syncSubscriptionRecord(
        User $user,
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
}
