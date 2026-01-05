<?php

namespace App\Controller\Front;

use App\Entity\User;
use Stripe\Stripe;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Checkout\Session as CheckoutSession;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Stripe\BillingPortal\Session as PortalSession;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;



 final class SubscriptionPageController extends AbstractController
{
    #[Route('/app/subscription', name: 'app_subscription')]
    public function index(): Response
    {

        return $this->render('subscription/index.html.twig', [
            'controller_name' => 'TestController',
        ]);
    }


        #[Route('/app/subscription/pro', name: 'app_subscription_pro')]
    public function pro(): Response
    {

        return $this->render('subscription/index.html.twig', [
            'controller_name' => 'TestController',
        ]);
    }


    #[Route('/app/contact', name: 'app_contact')]
    public function contact(): Response
    {

        return $this->render('subscription/index.html.twig', [
            'controller_name' => 'TestController',
        ]);
    }

     #[Route('/app/subscription/checkout/pro', name: 'app_subscription_checkout_pro')]
    public function checkoutPro(EntityManagerInterface $em): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

        $session = CheckoutSession::create([
            'mode' => 'subscription',
            'line_items' => [[
                'price' => $_ENV['STRIPE_PRICE_PRO'],
                'quantity' => 1,
            ]],
            'success_url' => rtrim($_ENV['APP_URL'], '/') . '/app/subscription/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => rtrim($_ENV['APP_URL'], '/') . '/app/subscription/cancel',

            // Important: on rattache la session à TON user
            'client_reference_id' => (string) $user->getId(),

            // Optionnel: préremplir l’email
            'customer_email' => $user->getEmail(),

            // Recommandé: metadata pour debug
            'metadata' => [
                'user_id' => (string) $user->getId(),
                'plan' => 'pro',
            ],
        ]);

        // Redirection Stripe-hosted Checkout
        return new RedirectResponse($session->url);
    }

    #[Route('/app/subscription/success', name: 'app_subscription_success')]
    public function success()
    {

        return $this->render('subscription/success.html.twig');
    }

    #[Route('/app/subscription/cancel', name: 'app_subscription_cancel')]
    public function cancel()
    {
        return $this->render('subscription/cancel.html.twig');
    }

#[Route('/app/subscription/portal', name: 'app_subscription_portal')]
public function portal(): RedirectResponse
{
    /** @var User $user */
    $user = $this->getUser();
    if (!$user || !$user->getStripeCustomerId()) {
        return $this->redirectToRoute('app_subscription');
    }

    \Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

    $session = PortalSession::create([
        'customer' => $user->getStripeCustomerId(),
        'return_url' => rtrim($_ENV['APP_URL'], '/') . '/app/subscription',
    ]);

    return new RedirectResponse($session->url);
}

}