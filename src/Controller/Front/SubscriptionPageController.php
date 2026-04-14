<?php

namespace App\Controller\Front;

use App\Entity\User;
use App\Entity\UserSubscription;
use App\Repository\UserRepository;
use App\Repository\UserSubscriptionRepository;
use App\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\BillingPortal\Session as PortalSession;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Stripe;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
    public function publicSuccess(
        Request $request,
        UserRepository $users,
        UserSubscriptionRepository $subscriptions,
        EntityManagerInterface $em,
        MailerService $mailer,
        UrlGeneratorInterface $urlGenerator,
        LoggerInterface $logger
    ): Response
    {
        $sessionId = (string) $request->query->get('session_id', '');

        if ($sessionId !== '') {
            try {
                Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

                /** @var \Stripe\Checkout\Session $session */
                $session = CheckoutSession::retrieve($sessionId, [
                    'expand' => ['customer', 'subscription'],
                ]);

                if ($session->status === 'complete') {
                    $user = $this->resolveCheckoutUser($session, $users, $em);

                    if ($user) {
                        $existingSubscription = $user->getUserSubscription() ?? $subscriptions->findOneBy(['user' => $user]);
                        $wasActive = $existingSubscription?->isActive() ?? false;

                        $this->syncSubscriptionRecord(
                            user: $user,
                            subscriptions: $subscriptions,
                            em: $em,
                            customerId: is_string($session->customer) ? $session->customer : ($session->customer->id ?? null),
                            subscriptionId: is_string($session->subscription) ? $session->subscription : ($session->subscription->id ?? null),
                            status: $session->payment_status === 'paid' ? 'active' : 'pending',
                            isActive: $session->payment_status === 'paid',
                            planCode: (string) ($session->metadata->plan ?? 'pro')
                        );

                        $em->flush();

                        if (!$wasActive && $session->payment_status === 'paid') {
                            $this->sendPostPaymentEmail($user, $mailer, $urlGenerator, $logger);
                            $em->flush();
                        }
                    }
                }
            } catch (\Throwable $e) {
                $logger->error('Public Stripe success fallback failed', [
                    'session_id' => $sessionId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

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

    private function resolveCheckoutUser(
        \Stripe\Checkout\Session $session,
        UserRepository $users,
        EntityManagerInterface $em
    ): ?User {
        $userId = $session->client_reference_id ?? null;
        $email = $session->customer_email ?? ($session->customer_details->email ?? null);

        if ($userId) {
            $user = $users->find((int) $userId);
            if ($user) {
                return $user;
            }
        }

        if (is_string($email) && $email !== '') {
            $user = $users->findOneBy(['email' => strtolower(trim($email))]);
            if ($user) {
                return $user;
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

    private function sendPostPaymentEmail(
        User $user,
        MailerService $mailer,
        UrlGeneratorInterface $urlGenerator,
        LoggerInterface $logger
    ): void {
        try {
            if (in_array('ROLE_GUEST', $user->getRoles(), true) && $user->getApiToken() !== null) {
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
            $logger->error('Public Stripe success fallback email failed', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail(),
                'message' => $e->getMessage(),
            ]);
        }
    }
}
