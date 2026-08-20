<?php

namespace App\Controller\Front;

use App\Entity\User;
use App\Service\StripeBillingService;
use App\Service\SubscriptionPlanService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SubscriptionPageController extends AbstractController
{
    public function __construct(
        private readonly SubscriptionPlanService $subscriptionPlans,
        private readonly StripeBillingService $stripeBilling,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/app/subscription', name: 'app_subscription')]
    #[Route('/app/subscription/pro', name: 'app_subscription_pro')]
    #[Route('/app/contact', name: 'app_contact')]
    public function index(): Response
    {
        return $this->render('subscription/index.html.twig', $this->getPlanPageData());
    }

    /** @return array<string, mixed> */
    private function getPlanPageData(): array
    {
        $user = $this->getUser();

        return [
            'freePlan' => $this->subscriptionPlans->getPlan(SubscriptionPlanService::PLAN_FREE),
            'proPlan' => $this->subscriptionPlans->getPlan(SubscriptionPlanService::PLAN_PRO),
            'teamPlan' => $this->subscriptionPlans->getPlan(SubscriptionPlanService::PLAN_TEAM),
            'currentPlan' => $user instanceof User ? $this->subscriptionPlans->getPlanForUser($user) : null,
        ];
    }

    #[Route('/app/subscription/checkout/pro', name: 'app_subscription_checkout_pro', methods: ['GET'])]
    public function checkoutPro(): RedirectResponse
    {
        return $this->startCheckout(SubscriptionPlanService::PLAN_PRO, false);
    }

    #[Route('/app/subscription/checkout/team', name: 'app_subscription_checkout_team', methods: ['GET'])]
    public function checkoutTeam(): RedirectResponse
    {
        return $this->startCheckout(SubscriptionPlanService::PLAN_TEAM, false);
    }

    #[Route('/pricing/pro/checkout', name: 'app_public_subscription_checkout_pro', methods: ['GET'])]
    public function publicCheckoutPro(): RedirectResponse
    {
        return $this->startCheckout(SubscriptionPlanService::PLAN_PRO, true);
    }

    #[Route('/pricing/team/checkout', name: 'app_public_subscription_checkout_team', methods: ['GET'])]
    public function publicCheckoutTeam(): RedirectResponse
    {
        return $this->startCheckout(SubscriptionPlanService::PLAN_TEAM, true);
    }

    #[Route('/app/subscription/success', name: 'app_subscription_success', methods: ['GET'])]
    public function success(Request $request): Response
    {
        $sessionId = trim((string) $request->query->get('session_id', ''));
        $stripeMode = trim((string) $request->query->get('stripe_mode', ''));
        /** @var User|null $user */
        $user = $this->getUser();
        $synchronized = false;

        if ($sessionId !== '' && $user !== null) {
            try {
                $synchronized = $this->stripeBilling->synchronizeCheckoutSession(
                    $sessionId,
                    $user,
                    stripeMode: $stripeMode !== '' ? $stripeMode : null,
                )?->isActive() ?? false;
            } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $this->logger->error('Authenticated Stripe Checkout synchronization failed.', [
                    'session_id' => $sessionId,
                    'user_id' => $user->getId(),
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $this->render('subscription/success.html.twig', ['synchronized' => $synchronized]);
    }

    #[Route('/app/subscription/status', name: 'app_subscription_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        return $this->json([
            'active' => $user?->hasActiveSubscription() ?? false,
            'plan' => $user?->getUserSubscription()?->getPlanCode(),
        ]);
    }

    #[Route('/app/subscription/cancel', name: 'app_subscription_cancel', methods: ['GET'])]
    public function cancel(Request $request): Response
    {
        return $this->render('subscription/cancel.html.twig', [
            'plan' => $this->normalizePlanQuery($request),
        ]);
    }

    #[Route('/pricing/pro/success', name: 'app_public_subscription_success', methods: ['GET'])]
    public function publicSuccess(Request $request): Response
    {
        return $this->handlePublicSuccess($request, SubscriptionPlanService::PLAN_PRO);
    }

    #[Route('/pricing/team/success', name: 'app_public_subscription_success_team', methods: ['GET'])]
    public function publicTeamSuccess(Request $request): Response
    {
        return $this->handlePublicSuccess($request, SubscriptionPlanService::PLAN_TEAM);
    }

    #[Route('/pricing/pro/cancel', name: 'app_public_subscription_cancel', methods: ['GET'])]
    public function publicCancel(): Response
    {
        return $this->render('subscription/public_cancel.html.twig', [
            'plan' => SubscriptionPlanService::PLAN_PRO,
            'retryRoute' => 'app_public_subscription_checkout_pro',
        ]);
    }

    #[Route('/pricing/team/cancel', name: 'app_public_subscription_cancel_team', methods: ['GET'])]
    public function publicTeamCancel(): Response
    {
        return $this->render('subscription/public_cancel.html.twig', [
            'plan' => SubscriptionPlanService::PLAN_TEAM,
            'retryRoute' => 'app_public_subscription_checkout_team',
        ]);
    }

    #[Route('/app/subscription/portal', name: 'app_subscription_portal', methods: ['GET'])]
    public function portal(): RedirectResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        try {
            $portalUrl = $user ? $this->stripeBilling->createPortalUrl($user) : null;
        } catch (\Throwable $exception) {
            $this->logger->error('Unable to create Stripe Billing Portal Session.', [
                'user_id' => $user?->getId(),
                'message' => $exception->getMessage(),
            ]);
            $this->addFlash('error', 'Le portail de facturation est temporairement indisponible.');
            $portalUrl = null;
        }

        return $portalUrl ? $this->redirect($portalUrl) : $this->redirectToRoute('app_subscription');
    }

    private function startCheckout(string $planCode, bool $publicCheckout): RedirectResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$publicCheckout && $user === null) {
            return $this->redirectToRoute('show_login');
        }

        if ($user?->hasActiveSubscription()) {
            try {
                $portalUrl = $this->stripeBilling->createPortalUrl($user);
                if ($portalUrl) {
                    return $this->redirect($portalUrl);
                }
            } catch (\Throwable $exception) {
                $this->logger->error('Unable to redirect an active subscriber to Stripe Billing Portal.', [
                    'user_id' => $user->getId(),
                    'message' => $exception->getMessage(),
                ]);
            }

            $this->addFlash('info', 'Votre abonnement est deja actif.');

            return $this->redirectToRoute('app_subscription');
        }

        try {
            $session = $this->stripeBilling->createCheckoutSession($user, $planCode, $publicCheckout);

            return $this->redirect((string) $session->url);
        } catch (\Throwable $exception) {
            $this->logger->error('Unable to create Stripe Checkout Session.', [
                'user_id' => $user?->getId(),
                'plan' => $planCode,
                'public_checkout' => $publicCheckout,
                'message' => $exception->getMessage(),
            ]);
            $this->addFlash('error', 'Le paiement est temporairement indisponible. Merci de reessayer dans quelques instants.');

            return $publicCheckout
                ? $this->redirect($this->generateUrl('app_landing') . '#pricing')
                : $this->redirectToRoute('app_subscription');
        }
    }

    private function handlePublicSuccess(Request $request, string $planCode): Response
    {
        $sessionId = trim((string) $request->query->get('session_id', ''));
        $stripeMode = trim((string) $request->query->get('stripe_mode', ''));
        $synchronized = false;

        if ($sessionId !== '') {
            try {
                $synchronized = $this->stripeBilling->synchronizeCheckoutSession(
                    $sessionId,
                    stripeMode: $stripeMode !== '' ? $stripeMode : null,
                )?->isActive() ?? false;
            } catch (\Throwable $exception) {
                $this->logger->error('Public Stripe Checkout synchronization failed.', [
                    'session_id' => $sessionId,
                    'plan' => $planCode,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $this->render('subscription/public_success.html.twig', [
            'plan' => $planCode,
            'synchronized' => $synchronized,
        ]);
    }

    private function normalizePlanQuery(Request $request): string
    {
        return mb_strtolower((string) $request->query->get('plan')) === SubscriptionPlanService::PLAN_TEAM
            ? SubscriptionPlanService::PLAN_TEAM
            : SubscriptionPlanService::PLAN_PRO;
    }
}
