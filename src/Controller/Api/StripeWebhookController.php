<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\UserSubscription;
use App\Repository\UserSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
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
        EntityManagerInterface $em
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
        } catch (\Throwable) {
            return new Response('Invalid signature', 400);
        }

        switch ($event->type) {
            case 'checkout.session.completed': {
                /** @var \Stripe\Checkout\Session $session */
                $session = $event->data->object;

                $userId = $session->client_reference_id ?? null;
                if (!$userId) {
                    break;
                }

                /** @var User|null $user */
                $user = $em->getRepository(User::class)->find((int) $userId);
                if (!$user) {
                    break;
                }

                $this->syncSubscriptionRecord(
                    $user,
                    $em,
                    customerId: is_string($session->customer) ? $session->customer : ($session->customer->id ?? null),
                    subscriptionId: is_string($session->subscription) ? $session->subscription : ($session->subscription->id ?? null),
                    planCode: 'pro'
                );

                $em->flush();
                break;
            }

            case 'invoice.payment_succeeded': {
                /** @var \Stripe\Invoice $invoice */
                $invoice = $event->data->object;

                $subId = is_string($invoice->subscription) ? $invoice->subscription : ($invoice->subscription->id ?? null);
                $customerId = is_string($invoice->customer) ? $invoice->customer : ($invoice->customer->id ?? null);

                $user = null;

                if ($subId) {
                    $user = $subscriptions->findOneBy(['stripeSubscriptionId' => $subId])?->getUser();
                }

                if (!$user && $customerId) {
                    $user = $subscriptions->findOneBy(['stripeCustomerId' => $customerId])?->getUser();
                }

                if (!$user) {
                    $email = $invoice->customer_email ?? ($invoice->customer_details->email ?? null);

                    if (is_string($email) && $email !== '') {
                        /** @var User|null $user */
                        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
                    }
                }

                if (!$user) {
                    break;
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
                    break;
                }

                $status = $sub->status ?? null;
                if (!is_string($status)) {
                    break;
                }

                $this->syncSubscriptionRecord(
                    $user,
                    $em,
                    customerId: is_string($sub->customer) ? $sub->customer : ($sub->customer->id ?? null),
                    subscriptionId: $subId,
                    status: $status,
                    isActive: in_array($status, ['active', 'trialing'], true)
                );

                $em->flush();
                break;
            }
        }

        return new Response('ok', 200);
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
