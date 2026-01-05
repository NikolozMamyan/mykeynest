<?php

namespace App\Controller\Api;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Webhook;
use Stripe\Stripe;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StripeWebhookController extends AbstractController
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
        } catch (\Throwable $e) {
            return new Response('Invalid signature', 400);
        }

        switch ($event->type) {

            // ✅ Activation après paiement/checkout finalisé
            case 'checkout.session.completed':
                /** @var \Stripe\Checkout\Session $session */
                $session = $event->data->object;

                // On retrouve ton user
                $userId = $session->client_reference_id ?? null;
                if (!$userId) break;

                $user = $users->find((int) $userId);
                if (!$user) break;

                // Enregistre ce qu’il faut pour gérer ensuite
                $user->setStripeCustomerId($session->customer);
                $user->setStripeSubscriptionId($session->subscription);

                // Exemple: flag pro
                $user->setPlan('pro');
                $user->setIsPro(true);

                $em->flush();
                break;

            // ✅ Maintien à jour du statut
            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
                // Ici tu peux mettre à jour isPro selon status/cancel_at_period_end etc.
                // (à partir de l’objet subscription $event->data->object)
                break;
        }

        return new Response('ok', 200);
    }
}
