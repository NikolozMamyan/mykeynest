<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Stripe;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StripeWebhookController
{
    #[Route('/api/stripe/webhook', name: 'stripe_webhook', methods: ['POST'])]
    public function handle(
        Request $request,
        UserRepository $users,
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

            // 1) Checkout terminé => on stocke les IDs Stripe
            case 'checkout.session.completed': {
                /** @var \Stripe\Checkout\Session $session */
                $session = $event->data->object;

                $userId = $session->client_reference_id ?? null;
                if (!$userId) break;

                /** @var User|null $user */
                $user = $users->find((int) $userId);
                if (!$user) break;

                $customerId = is_string($session->customer) ? $session->customer : ($session->customer->id ?? null);
                $subId      = is_string($session->subscription) ? $session->subscription : ($session->subscription->id ?? null);

                if ($customerId) $user->setStripeCustomerId($customerId);
                if ($subId) $user->setStripeSubscriptionId($subId);

                $em->flush();
                break;
            }

            // 2) Paiement de la facture OK => abonnement actif => TRUE
            case 'invoice.payment_succeeded': {
                /** @var \Stripe\Invoice $invoice */
                $invoice = $event->data->object;

                $subId = is_string($invoice->subscription) ? $invoice->subscription : ($invoice->subscription->id ?? null);
                $customerId = is_string($invoice->customer) ? $invoice->customer : ($invoice->customer->id ?? null);

                if (!$subId && !$customerId) break;

                /** @var User|null $user */
                $user = null;

                if ($subId) {
                    $user = $users->findOneBy(['stripeSubscriptionId' => $subId]);
                }
                if (!$user && $customerId) {
                    $user = $users->findOneBy(['stripeCustomerId' => $customerId]);
                }
                if (!$user) break;

                $user->setIsSubscribed(true);
                if ($customerId) $user->setStripeCustomerId($customerId);
                if ($subId) $user->setStripeSubscriptionId($subId);

                $em->flush();
                break;
            }

            // 3) Abonnement supprimé => FALSE
            case 'customer.subscription.deleted': {
                /** @var \Stripe\Subscription $sub */
                $sub = $event->data->object;

                $subId = $sub->id ?? null;
                if (!$subId) break;

                /** @var User|null $user */
                $user = $users->findOneBy(['stripeSubscriptionId' => $subId]);
                if (!$user) break;

                $user->setIsSubscribed(false);
                $user->setStripeSubscriptionId(null);

                $em->flush();
                break;
            }

            // (optionnel) Mise à jour : si past_due/canceled => false, active/trialing => true
            case 'customer.subscription.updated': {
                /** @var \Stripe\Subscription $sub */
                $sub = $event->data->object;

                $subId = $sub->id ?? null;
                if (!$subId) break;

                /** @var User|null $user */
                $user = $users->findOneBy(['stripeSubscriptionId' => $subId]);
                if (!$user) break;

                $status = $sub->status ?? null;
                if (is_string($status)) {
                    $user->setIsSubscribed(in_array($status, ['active', 'trialing'], true));
                    $em->flush();
                }
                break;
            }
        }

        return new Response('ok', 200);
    }
}
