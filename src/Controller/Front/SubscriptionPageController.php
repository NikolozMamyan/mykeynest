<?php

namespace App\Controller\Front;

use App\Entity\User;
use Stripe\BillingPortal\Session as PortalSession;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Stripe;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SubscriptionPageController extends AbstractController
{
    private function createCheckoutRedirect(?User $user, string $successUrl, string $cancelUrl): RedirectResponse
    {
        Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

        $checkoutParams = [
            'mode' => 'subscription',
            'line_items' => [[
                'price' => $_ENV['STRIPE_PRICE_PRO'],
                'quantity' => 1,
            ]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'plan' => 'pro',
                'checkout_origin' => 'landing_public',
            ],
        ];

        if ($user) {
            $checkoutParams['client_reference_id'] = (string) $user->getId();
            $checkoutParams['metadata']['user_id'] = (string) $user->getId();

            if ($user->getStripeCustomerId()) {
                $checkoutParams['customer'] = $user->getStripeCustomerId();
            } else {
                $checkoutParams['customer_email'] = $user->getEmail();
            }
        }

        $session = CheckoutSession::create($checkoutParams);

        return new RedirectResponse($session->url);
    }

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
    public function checkoutPro(): RedirectResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('show_login');
        }

        return $this->createCheckoutRedirect(
            $user,
            rtrim($_ENV['APP_URL'], '/') . '/app/subscription/success?session_id={CHECKOUT_SESSION_ID}',
            rtrim($_ENV['APP_URL'], '/') . '/app/subscription/cancel'
        );
    }

    #[Route('/pricing/pro/checkout', name: 'app_public_subscription_checkout_pro', methods: ['GET'])]
    public function publicCheckoutPro(): RedirectResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        return $this->createCheckoutRedirect(
            $user,
            rtrim($_ENV['APP_URL'], '/') . '/pricing/pro/success?session_id={CHECKOUT_SESSION_ID}',
            rtrim($_ENV['APP_URL'], '/') . '/pricing/pro/cancel'
        );
    }

    #[Route('/app/subscription/success', name: 'app_subscription_success')]
    public function success(): Response
    {
        return $this->render('subscription/success.html.twig');
    }

    #[Route('/app/subscription/cancel', name: 'app_subscription_cancel')]
    public function cancel(): Response
    {
        return $this->render('subscription/cancel.html.twig');
    }

    #[Route('/pricing/pro/success', name: 'app_public_subscription_success', methods: ['GET'])]
    public function publicSuccess(): Response
    {
        return $this->render('subscription/public_success.html.twig');
    }

    #[Route('/pricing/pro/cancel', name: 'app_public_subscription_cancel', methods: ['GET'])]
    public function publicCancel(): Response
    {
        return $this->render('subscription/public_cancel.html.twig');
    }

    #[Route('/app/subscription/portal', name: 'app_subscription_portal')]
    public function portal(): RedirectResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        $customerId = $user?->getUserSubscription()?->getStripeCustomerId() ?? $user?->getStripeCustomerId();

        if (!$user || !$customerId) {
            return $this->redirectToRoute('app_subscription');
        }

        Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

        $session = PortalSession::create([
            'customer' => $customerId,
            'return_url' => rtrim($_ENV['APP_URL'], '/') . '/app/subscription',
        ]);

        return new RedirectResponse($session->url);
    }
}
