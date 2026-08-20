<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\UserSubscription;
use App\Repository\UserRepository;
use App\Repository\UserSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Customer;
use Stripe\Event;
use Stripe\Invoice;
use Stripe\StripeClient;
use Stripe\StripeObject;
use Stripe\Subscription;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class StripeBillingService
{
    public function __construct(
        private readonly StripeEnvironmentManager $stripeEnvironments,
        private readonly StripePlanCatalog $plans,
        private readonly UserRepository $users,
        private readonly UserSubscriptionRepository $subscriptions,
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerService $mailer,
        private readonly AdminNotificationService $adminNotifications,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        private readonly string $appUrl,
    ) {
    }

    public function createCheckoutSession(?User $user, string $planCode, bool $publicCheckout): CheckoutSession
    {
        $planCode = $this->plans->normalizePaidPlan($planCode);
        $stripeMode = $this->stripeEnvironments->getActiveMode();
        $stripe = $this->stripeEnvironments->createClient($stripeMode);
        $baseUrl = rtrim($this->appUrl, '/');
        if ($baseUrl === '') {
            throw new \LogicException('APP_URL is not configured.');
        }

        $successPath = $publicCheckout
            ? sprintf('/pricing/%s/success', $planCode)
            : '/app/subscription/success';
        $cancelPath = $publicCheckout
            ? sprintf('/pricing/%s/cancel', $planCode)
            : sprintf('/app/subscription/cancel?plan=%s', $planCode);
        $metadata = [
            'plan' => $planCode,
            'stripe_mode' => $stripeMode,
            'checkout_origin' => $publicCheckout ? 'landing_public' : 'authenticated_app',
        ];
        $lineItem = $this->plans->getCheckoutLineItem(
            $planCode,
            $this->stripeEnvironments->getPriceId($planCode, $stripeMode),
        );
        $this->assertConfiguredPrice($stripe, $planCode, $lineItem['price']);

        $params = [
            'mode' => 'subscription',
            'line_items' => [$lineItem],
            'success_url' => $baseUrl . $successPath . '?session_id={CHECKOUT_SESSION_ID}&stripe_mode=' . $stripeMode,
            'cancel_url' => $baseUrl . $cancelPath,
            'allow_promotion_codes' => true,
            'metadata' => $metadata,
            'subscription_data' => ['metadata' => $metadata],
        ];

        if ($user !== null) {
            if ($user->getId() === null) {
                throw new \LogicException('The checkout user must be persisted.');
            }

            $params['client_reference_id'] = (string) $user->getId();
            $params['metadata']['user_id'] = (string) $user->getId();
            $params['subscription_data']['metadata']['user_id'] = (string) $user->getId();

            $storedSubscription = $user->getUserSubscription();
            if (
                $storedSubscription?->getStripeMode() === $stripeMode
                && $storedSubscription->getStripeCustomerId()
            ) {
                $params['customer'] = $storedSubscription->getStripeCustomerId();
            } else {
                $params['customer_email'] = $user->getEmail();
            }
        }

        return $stripe->checkout->sessions->create($params);
    }

    public function createPortalUrl(User $user): ?string
    {
        $customerId = $user->getStripeCustomerId();
        if (!$customerId) {
            return null;
        }

        $stripeMode = $user->getUserSubscription()?->getStripeMode()
            ?? $this->stripeEnvironments->getActiveMode();
        $session = $this->stripeEnvironments->createClient($stripeMode)->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => rtrim($this->appUrl, '/') . '/app/subscription',
        ]);

        return $session->url;
    }

    public function synchronizeCheckoutSession(
        string $sessionId,
        ?User $expectedUser = null,
        string $source = 'checkout_session',
        ?string $stripeMode = null,
    ): ?UserSubscription
    {
        $stripeMode = $this->stripeEnvironments->normalizeMode(
            $stripeMode ?? $this->stripeEnvironments->getActiveMode(),
        );
        $stripe = $this->stripeEnvironments->createClient($stripeMode);
        /** @var CheckoutSession $session */
        $session = $stripe->checkout->sessions->retrieve($sessionId, [
            'expand' => ['customer', 'subscription', 'subscription.items.data.price', 'subscription.latest_invoice'],
        ]);

        return $this->synchronizeCheckoutObject($session, $expectedUser, $source, $stripeMode, $stripe);
    }

    public function processWebhookEvent(Event $event, string $stripeMode): bool
    {
        $stripeMode = $this->stripeEnvironments->normalizeMode($stripeMode);
        $stripe = $this->stripeEnvironments->createClient($stripeMode);
        $eventType = (string) $event->type;

        if (in_array($eventType, [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
            'checkout.session.async_payment_failed',
        ], true)) {
            $sessionId = $this->readString($event->data->object ?? null, 'id');
            if ($sessionId === null) {
                throw new \RuntimeException(sprintf('Stripe event %s has no Checkout Session id.', $eventType));
            }

            $this->synchronizeCheckoutSession($sessionId, null, $eventType, $stripeMode);

            return true;
        }

        if (in_array($eventType, [
            'invoice.paid',
            'invoice.payment_succeeded',
            'invoice.payment_failed',
        ], true)) {
            /** @var Invoice $invoice */
            $invoice = $event->data->object;
            $subscriptionId = $this->extractInvoiceSubscriptionId($invoice);
            if ($subscriptionId === null) {
                $this->logger->info('Stripe invoice event ignored because it is not linked to a subscription.', [
                    'event_id' => $event->id,
                    'event_type' => $eventType,
                ]);

                return true;
            }

            $this->synchronizeSubscriptionById($subscriptionId, $eventType, $stripeMode, $stripe);

            return true;
        }

        if (in_array($eventType, [
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.paused',
            'customer.subscription.resumed',
        ], true)) {
            $subscriptionId = $this->readString($event->data->object ?? null, 'id');
            if ($subscriptionId === null) {
                throw new \RuntimeException(sprintf('Stripe event %s has no subscription id.', $eventType));
            }

            $this->synchronizeSubscriptionById($subscriptionId, $eventType, $stripeMode, $stripe);

            return true;
        }

        if ($eventType === 'customer.subscription.deleted') {
            /** @var Subscription $subscription */
            $subscription = $event->data->object;
            $this->synchronizeSubscriptionObject($subscription, $eventType, $stripeMode, $stripe);

            return true;
        }

        return false;
    }

    private function synchronizeCheckoutObject(
        CheckoutSession $session,
        ?User $expectedUser,
        string $source,
        string $stripeMode,
        StripeClient $stripe,
    ): ?UserSubscription
    {
        $subscriptionValue = $session->subscription ?? null;
        $subscriptionId = $this->toStripeId($subscriptionValue);
        $customerId = $this->toStripeId($session->customer ?? null);
        $user = $this->resolveCheckoutUser($session, $expectedUser, $subscriptionId, $customerId, $stripeMode);

        if ($user === null) {
            $this->logger->warning('Stripe Checkout Session could not be associated with a MYKEYNEST user.', [
                'session_id' => $session->id,
                'subscription_id' => $subscriptionId,
                'customer_id' => $customerId,
            ]);

            return null;
        }

        if ($subscriptionValue instanceof Subscription) {
            return $this->synchronizeSubscriptionObject(
                $subscriptionValue,
                $source,
                $stripeMode,
                $stripe,
                $user,
                in_array((string) ($session->payment_status ?? ''), ['paid', 'no_payment_required'], true),
            );
        }

        if ($subscriptionId !== null) {
            return $this->synchronizeSubscriptionById(
                $subscriptionId,
                $source,
                $stripeMode,
                $stripe,
                $user,
                in_array((string) ($session->payment_status ?? ''), ['paid', 'no_payment_required'], true),
            );
        }

        $record = $this->getOrCreateSubscription($user);
        if ($customerId !== null) {
            $record->setStripeCustomerId($customerId);
        }
        $record->setStripeMode($stripeMode);

        // Never let a late or incomplete Checkout event downgrade existing paid access.
        if (!$record->isActive()) {
            $record->setStatus('pending')->setIsActive(false);
        }

        $record->touch();
        $this->entityManager->flush();

        return $record;
    }

    private function synchronizeSubscriptionById(
        string $subscriptionId,
        string $source,
        string $stripeMode,
        StripeClient $stripe,
        ?User $knownUser = null,
        ?bool $paymentConfirmedOverride = null,
    ): ?UserSubscription {
        /** @var Subscription $subscription */
        $subscription = $stripe->subscriptions->retrieve($subscriptionId, [
            'expand' => ['customer', 'items.data.price', 'latest_invoice'],
        ]);

        $paymentConfirmed = $paymentConfirmedOverride ?? match ($source) {
            'invoice.paid', 'invoice.payment_succeeded', 'checkout.session.async_payment_succeeded' => true,
            'invoice.payment_failed', 'checkout.session.async_payment_failed' => false,
            default => null,
        };

        return $this->synchronizeSubscriptionObject(
            $subscription,
            $source,
            $stripeMode,
            $stripe,
            $knownUser,
            $paymentConfirmed,
        );
    }

    private function synchronizeSubscriptionObject(
        Subscription $stripeSubscription,
        string $source,
        string $stripeMode,
        StripeClient $stripe,
        ?User $knownUser = null,
        ?bool $paymentConfirmed = null,
    ): ?UserSubscription {
        $subscriptionId = $this->readString($stripeSubscription, 'id');
        $customerId = $this->toStripeId($stripeSubscription->customer ?? null);
        $lineItem = $stripeSubscription->items->data[0] ?? null;
        $priceId = $this->toStripeId($lineItem?->price ?? null);
        $recordBySubscription = $subscriptionId !== null
            ? $this->subscriptions->findOneBy([
                'stripeSubscriptionId' => $subscriptionId,
                'stripeMode' => $stripeMode,
            ])
            : null;
        $recordByCustomer = $recordBySubscription === null && $customerId !== null
            ? $this->subscriptions->findOneBy([
                'stripeCustomerId' => $customerId,
                'stripeMode' => $stripeMode,
            ])
            : null;
        $knownRecord = $recordBySubscription ?? $recordByCustomer;
        $planCode = $this->plans->resolvePlanFromPrice(
            $priceId,
            $this->safePriceId(SubscriptionPlanService::PLAN_PRO, $stripeMode),
            $this->safePriceId(SubscriptionPlanService::PLAN_TEAM, $stripeMode),
        );
        $metadataPlan = $this->normalizeMetadataPlan($this->readString($stripeSubscription->metadata ?? null, 'plan'));

        if (
            $recordBySubscription === null
            && $recordByCustomer?->isActive()
            && $recordByCustomer->getStripeSubscriptionId() !== null
            && $recordByCustomer->getStripeSubscriptionId() !== $subscriptionId
        ) {
            $this->logger->warning('Older Stripe subscription event ignored because another subscription is active for the customer.', [
                'incoming_subscription_id' => $subscriptionId,
                'active_subscription_id' => $recordByCustomer->getStripeSubscriptionId(),
                'customer_id' => $customerId,
                'source' => $source,
            ]);

            return $recordByCustomer;
        }

        // Ignore subscriptions from unrelated Stripe products on the same Stripe account.
        if ($planCode === null && $recordBySubscription === null && $metadataPlan === null) {
            $this->logger->info('Stripe subscription ignored because its Price is not configured for MYKEYNEST.', [
                'subscription_id' => $subscriptionId,
                'price_id' => $priceId,
                'source' => $source,
            ]);

            return null;
        }

        $user = $knownUser
            ?? $knownRecord?->getUser()
            ?? $this->resolveSubscriptionUser($stripeSubscription, $customerId, $stripe);
        if ($user === null) {
            $this->logger->warning('Stripe subscription could not be associated with a MYKEYNEST user.', [
                'subscription_id' => $subscriptionId,
                'customer_id' => $customerId,
                'source' => $source,
            ]);

            return null;
        }

        $record = $knownRecord ?? $this->getOrCreateSubscription($user);
        if (
            $record->isActive()
            && $record->getStripeMode() !== $stripeMode
            && $record->getStripeSubscriptionId() !== $subscriptionId
        ) {
            $this->logger->warning('Stripe event ignored because the user has another active subscription in a different mode.', [
                'incoming_mode' => $stripeMode,
                'active_mode' => $record->getStripeMode(),
                'incoming_subscription_id' => $subscriptionId,
                'active_subscription_id' => $record->getStripeSubscriptionId(),
                'user_id' => $user->getId(),
                'source' => $source,
            ]);

            return $record;
        }
        $wasActive = $record->isActive();
        $status = $this->readString($stripeSubscription, 'status') ?? 'unknown';
        $latestInvoice = $stripeSubscription->latest_invoice ?? null;
        $latestInvoicePaid = is_object($latestInvoice) && (
            ($latestInvoice->paid ?? false) === true
            || $this->readString($latestInvoice, 'status') === 'paid'
        );
        $isActive = $status === 'trialing'
            || ($status === 'active' && ($wasActive || $paymentConfirmed === true || $latestInvoicePaid));
        $resolvedPlan = $planCode
            ?? $metadataPlan
            ?? $record->getPlanCode()
            ?? SubscriptionPlanService::PLAN_PRO;

        $record
            ->setUser($user)
            ->setStripeSubscriptionId($subscriptionId)
            ->setStatus($status)
            ->setIsActive($isActive)
            ->setPlanCode($resolvedPlan)
            ->setStripePriceId($priceId)
            ->setStripeMode($stripeMode)
            ->setQuantity((int) ($lineItem?->quantity ?? 1))
            ->setCurrentPeriodEnd($this->timestampToDate(
                $lineItem?->current_period_end ?? ($stripeSubscription->current_period_end ?? null)
            ))
            ->setCancelAtPeriodEnd((bool) ($stripeSubscription->cancel_at_period_end ?? false))
            ->touch();

        if ($customerId !== null) {
            $record->setStripeCustomerId($customerId);
        }

        $user->setUserSubscription($record);
        $this->entityManager->flush();

        if (!$wasActive && $isActive) {
            $this->sendActivationNotifications($user, $resolvedPlan, $source);
            $this->entityManager->flush();
        }

        $this->logger->info('MYKEYNEST subscription synchronized from Stripe.', [
            'user_id' => $user->getId(),
            'subscription_id' => $subscriptionId,
            'plan' => $resolvedPlan,
            'status' => $status,
            'is_active' => $isActive,
            'source' => $source,
        ]);

        return $record;
    }

    private function resolveCheckoutUser(
        CheckoutSession $session,
        ?User $expectedUser,
        ?string $subscriptionId,
        ?string $customerId,
        string $stripeMode,
    ): ?User {
        $referenceId = $this->readString($session, 'client_reference_id')
            ?? $this->readString($session->metadata ?? null, 'user_id');

        if ($expectedUser !== null) {
            if ($referenceId === null || (string) $expectedUser->getId() !== $referenceId) {
                throw new AccessDeniedException('This Stripe Checkout Session belongs to another user.');
            }

            return $expectedUser;
        }

        if ($subscriptionId !== null) {
            $stored = $this->subscriptions->findOneBy([
                'stripeSubscriptionId' => $subscriptionId,
                'stripeMode' => $stripeMode,
            ]);
            if ($stored?->getUser()) {
                return $stored->getUser();
            }
        }

        if ($customerId !== null) {
            $stored = $this->subscriptions->findOneBy([
                'stripeCustomerId' => $customerId,
                'stripeMode' => $stripeMode,
            ]);
            if ($stored?->getUser()) {
                return $stored->getUser();
            }
        }

        if ($referenceId !== null && ctype_digit($referenceId)) {
            $user = $this->users->find((int) $referenceId);
            if ($user) {
                return $user;
            }
        }

        $email = $this->readString($session, 'customer_email')
            ?? $this->readString($session->customer_details ?? null, 'email')
            ?? $this->readString($session->customer ?? null, 'email');

        return $this->findOrCreateCheckoutUser($email);
    }

    private function resolveSubscriptionUser(
        Subscription $subscription,
        ?string $customerId,
        StripeClient $stripe,
    ): ?User
    {
        $userId = $this->readString($subscription->metadata ?? null, 'user_id');
        if ($userId !== null && ctype_digit($userId)) {
            $user = $this->users->find((int) $userId);
            if ($user) {
                return $user;
            }
        }

        $customer = $subscription->customer ?? null;
        if (!$customer instanceof Customer && $customerId !== null) {
            $customer = $stripe->customers->retrieve($customerId);
        }

        return $this->findOrCreateCheckoutUser($this->readString($customer, 'email'));
    }

    private function findOrCreateCheckoutUser(?string $email): ?User
    {
        $email = mb_strtolower(trim((string) $email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $user = $this->users->findOneBy(['email' => $email]);
        if ($user) {
            return $user;
        }

        $user = (new User())
            ->setEmail($email)
            ->setCompany('')
            ->setPassword('')
            ->setRoles(['ROLE_GUEST'])
            ->setApiToken(bin2hex(random_bytes(32)))
            ->setTokenExpiresAt(new \DateTimeImmutable('+7 days'));
        $user->regenerateApiExtensionToken();
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function getOrCreateSubscription(User $user): UserSubscription
    {
        $record = $user->getUserSubscription() ?? $this->subscriptions->findOneBy(['user' => $user]);
        if ($record) {
            return $record;
        }

        $record = (new UserSubscription())->setUser($user);
        $user->setUserSubscription($record);
        $this->entityManager->persist($record);

        return $record;
    }

    private function sendActivationNotifications(User $user, string $planCode, string $source): void
    {
        $planName = strtoupper($planCode);

        try {
            if (in_array('ROLE_GUEST', $user->getRoles(), true)) {
                $expiresAt = new \DateTimeImmutable('+7 days');
                $user->setApiToken(bin2hex(random_bytes(32)));
                $user->setTokenExpiresAt($expiresAt);

                $setupUrl = $this->urlGenerator->generate('app_guest_register', [
                    'token' => $user->getApiToken(),
                    'email' => $user->getEmail(),
                ], UrlGeneratorInterface::ABSOLUTE_URL);

                $this->mailer->send(
                    (string) $user->getEmail(),
                    sprintf('Votre abonnement MYKEYNEST %s est actif', $planName),
                    'emails/pro_checkout_activation.html.twig',
                    [
                        'user' => $user,
                        'setup_url' => $setupUrl,
                        'expiresAt' => $expiresAt,
                        'planName' => $planName,
                    ]
                );
            } else {
                $loginUrl = $this->urlGenerator->generate('show_login', [], UrlGeneratorInterface::ABSOLUTE_URL);
                $forgotPasswordUrl = $this->urlGenerator->generate('app_forgot_password_request', [], UrlGeneratorInterface::ABSOLUTE_URL);

                $this->mailer->send(
                    (string) $user->getEmail(),
                    sprintf('Votre abonnement MYKEYNEST %s est actif', $planName),
                    'emails/pro_checkout_existing_user.html.twig',
                    [
                        'user' => $user,
                        'planName' => $planName,
                        'login_url' => $loginUrl,
                        'forgot_password_url' => $forgotPasswordUrl,
                    ]
                );
            }
        } catch (\Throwable $exception) {
            $this->logger->error('Stripe subscription activation email failed.', [
                'user_id' => $user->getId(),
                'plan' => $planCode,
                'message' => $exception->getMessage(),
            ]);
        }

        try {
            $this->adminNotifications->notifySubscriptionActivated($user, $source);
        } catch (\Throwable $exception) {
            $this->logger->error('Stripe subscription admin notification failed.', [
                'user_id' => $user->getId(),
                'plan' => $planCode,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function extractInvoiceSubscriptionId(Invoice $invoice): ?string
    {
        $parent = $invoice->parent ?? null;
        $subscriptionDetails = $parent?->subscription_details ?? null;
        $subscription = $subscriptionDetails?->subscription ?? ($invoice->subscription ?? null);

        return $this->toStripeId($subscription);
    }

    private function toStripeId(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return $this->readString($value, 'id');
    }

    private function readString(mixed $source, string $key): ?string
    {
        $value = null;
        if (is_array($source)) {
            $value = $source[$key] ?? null;
        } elseif ($source instanceof StripeObject || is_object($source)) {
            $value = $source->{$key} ?? null;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function normalizeMetadataPlan(?string $planCode): ?string
    {
        $planCode = mb_strtolower(trim((string) $planCode));

        return in_array($planCode, [SubscriptionPlanService::PLAN_PRO, SubscriptionPlanService::PLAN_TEAM], true)
            ? $planCode
            : null;
    }

    private function timestampToDate(mixed $timestamp): ?\DateTimeImmutable
    {
        if (!is_int($timestamp) && !ctype_digit((string) $timestamp)) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp((int) $timestamp);
    }

    private function assertConfiguredPrice(StripeClient $stripe, string $planCode, string $priceId): void
    {
        $price = $stripe->prices->retrieve($priceId);
        $interval = $this->readString($price->recurring ?? null, 'interval');

        if (
            ($price->active ?? false) !== true
            || mb_strtolower((string) ($price->currency ?? '')) !== 'eur'
            || (int) ($price->unit_amount ?? 0) !== $this->plans->getExpectedMonthlyAmount($planCode)
            || $interval !== 'month'
        ) {
            throw new \LogicException(sprintf(
                'Stripe Price %s does not match the expected monthly %s offer.',
                $priceId,
                $planCode,
            ));
        }
    }

    private function safePriceId(string $planCode, string $stripeMode): string
    {
        try {
            return $this->stripeEnvironments->getPriceId($planCode, $stripeMode);
        } catch (\LogicException) {
            return '';
        }
    }
}
