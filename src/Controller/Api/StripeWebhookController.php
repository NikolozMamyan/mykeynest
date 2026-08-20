<?php

namespace App\Controller\Api;

use App\Entity\StripeWebhookEvent;
use App\Repository\StripeWebhookEventRepository;
use App\Service\StripeBillingService;
use App\Service\StripeEnvironmentManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly StripeBillingService $stripeBilling,
        private readonly StripeWebhookEventRepository $processedEvents,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly StripeEnvironmentManager $stripeEnvironments,
    ) {
    }

    #[Route('/stripe/webhook', name: 'stripe_webhook', methods: ['POST'])]
    public function handle(Request $request): Response
    {
        $signature = $request->headers->get('Stripe-Signature');
        if (!$signature) {
            return new Response('Missing signature', Response::HTTP_BAD_REQUEST);
        }

        $event = null;
        $stripeMode = null;
        foreach ($this->stripeEnvironments->getWebhookVerificationModes() as $candidateMode) {
            $webhookSecret = $this->stripeEnvironments->getWebhookSecret($candidateMode);
            if (!str_starts_with($webhookSecret, 'whsec_')) {
                continue;
            }

            try {
                $event = Webhook::constructEvent($request->getContent(), $signature, $webhookSecret);
                $stripeMode = $candidateMode;
                break;
            } catch (\Throwable) {
                // The signature may belong to the other configured Stripe mode.
            }
        }

        if ($event === null || $stripeMode === null) {
            $this->logger->warning('Stripe webhook rejected.', [
                'reason' => 'No configured Stripe webhook secret matched the signature.',
            ]);

            return new Response('Invalid signature', Response::HTTP_BAD_REQUEST);
        }

        $eventId = trim((string) $event->id);
        $eventType = trim((string) $event->type);
        if ($eventId === '' || $eventType === '') {
            return new Response('Invalid event', Response::HTTP_BAD_REQUEST);
        }

        if ($this->processedEvents->hasProcessed($eventId, $stripeMode)) {
            return new Response('ok', Response::HTTP_OK);
        }

        try {
            $handled = $this->entityManager->wrapInTransaction(function () use ($event, $eventId, $eventType, $stripeMode): bool {
                // Claim the event before any side effect. The unique index serializes
                // simultaneous deliveries of the same Stripe event.
                $this->entityManager->persist(new StripeWebhookEvent($eventId, $eventType, $stripeMode));
                $this->entityManager->flush();

                return $this->stripeBilling->processWebhookEvent($event, $stripeMode);
            });

            $this->logger->info('Stripe webhook processed.', [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'stripe_mode' => $stripeMode,
                'handled' => $handled,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery already finished the same event successfully.
            return new Response('ok', Response::HTTP_OK);
        } catch (\Throwable $exception) {
            $this->logger->error('Stripe webhook processing failed.', [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'stripe_mode' => $stripeMode,
                'message' => $exception->getMessage(),
            ]);

            // A 5xx response asks Stripe to retry later.
            return new Response('Webhook processing failed', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new Response('ok', Response::HTTP_OK);
    }
}
