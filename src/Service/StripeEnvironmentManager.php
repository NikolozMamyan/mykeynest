<?php

namespace App\Service;

use App\Entity\StripePaymentConfiguration;
use App\Repository\StripePaymentConfigurationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\StripeClient;

final class StripeEnvironmentManager
{
    public const MODE_SANDBOX = 'sandbox';
    public const MODE_PRODUCTION = 'production';

    public function __construct(
        private readonly StripePaymentConfigurationRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $sandboxSecretKey,
        private readonly string $sandboxProPriceId,
        private readonly string $sandboxTeamPriceId,
        private readonly string $sandboxWebhookSecret,
        private readonly string $productionSecretKey,
        private readonly string $productionProPriceId,
        private readonly string $productionTeamPriceId,
        private readonly string $productionWebhookSecret,
    ) {
    }

    public function getActiveMode(): string
    {
        $storedMode = $this->repository->findConfiguration()?->getActiveMode();

        return $storedMode !== null
            ? $this->normalizeMode($storedMode)
            : self::MODE_SANDBOX;
    }

    public function activateMode(string $mode, ?string $updatedBy = null): StripePaymentConfiguration
    {
        $mode = $this->normalizeMode($mode);
        if (!$this->isReady($mode)) {
            throw new \LogicException(sprintf('Stripe mode "%s" is incomplete.', $mode));
        }

        $configuration = $this->repository->findConfiguration() ?? new StripePaymentConfiguration();
        $configuration
            ->setActiveMode($mode)
            ->markUpdated($updatedBy);

        $this->entityManager->persist($configuration);
        $this->entityManager->flush();

        return $configuration;
    }

    public function normalizeMode(string $mode): string
    {
        $mode = mb_strtolower(trim($mode));
        if (!in_array($mode, [self::MODE_SANDBOX, self::MODE_PRODUCTION], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported Stripe mode "%s".', $mode));
        }

        return $mode;
    }

    public function createClient(?string $mode = null): StripeClient
    {
        $mode = $this->normalizeMode($mode ?? $this->getActiveMode());
        $secretKey = $this->values($mode)['secretKey'];
        $expectedPrefix = $mode === self::MODE_SANDBOX ? 'sk_test_' : 'sk_live_';
        if (!str_starts_with($secretKey, $expectedPrefix)) {
            throw new \LogicException(sprintf('Stripe secret key is not configured for %s mode.', $mode));
        }

        return new StripeClient($secretKey);
    }

    public function getPriceId(string $planCode, ?string $mode = null): string
    {
        $mode = $this->normalizeMode($mode ?? $this->getActiveMode());
        $planCode = mb_strtolower(trim($planCode));
        if (!in_array($planCode, [SubscriptionPlanService::PLAN_PRO, SubscriptionPlanService::PLAN_TEAM], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported Stripe plan "%s".', $planCode));
        }

        $key = $planCode === SubscriptionPlanService::PLAN_TEAM ? 'teamPriceId' : 'proPriceId';
        $priceId = $this->values($mode)[$key];
        if (!str_starts_with($priceId, 'price_')) {
            throw new \LogicException(sprintf('Stripe Price is not configured for the %s plan in %s mode.', $planCode, $mode));
        }

        return $priceId;
    }

    public function getWebhookSecret(string $mode): string
    {
        return $this->values($this->normalizeMode($mode))['webhookSecret'];
    }

    /** @return list<string> */
    public function getWebhookVerificationModes(): array
    {
        $active = $this->getActiveMode();
        $other = $active === self::MODE_SANDBOX ? self::MODE_PRODUCTION : self::MODE_SANDBOX;

        return [$active, $other];
    }

    public function isReady(string $mode): bool
    {
        $mode = $this->normalizeMode($mode);
        $values = $this->values($mode);
        $suffix = $mode === self::MODE_SANDBOX ? 'test_' : 'live_';

        return str_starts_with($values['secretKey'], 'sk_' . $suffix)
            && str_starts_with($values['proPriceId'], 'price_')
            && str_starts_with($values['teamPriceId'], 'price_')
            && str_starts_with($values['webhookSecret'], 'whsec_');
    }

    /**
     * @return array{
     *   mode:string,
     *   active:bool,
     *   ready:bool,
     *   secretKey:array{configured:bool,masked:string},
     *   proPriceId:array{configured:bool,masked:string},
     *   teamPriceId:array{configured:bool,masked:string},
     *   webhookSecret:array{configured:bool,masked:string}
     * }
     */
    public function getModeStatus(string $mode): array
    {
        $mode = $this->normalizeMode($mode);
        $values = $this->values($mode);

        return [
            'mode' => $mode,
            'active' => $mode === $this->getActiveMode(),
            'ready' => $this->isReady($mode),
            'secretKey' => $this->describe($values['secretKey'], $mode === self::MODE_SANDBOX ? 'sk_test_' : 'sk_live_'),
            'proPriceId' => $this->describe($values['proPriceId'], 'price_'),
            'teamPriceId' => $this->describe($values['teamPriceId'], 'price_'),
            'webhookSecret' => $this->describe($values['webhookSecret'], 'whsec_'),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function getAdminStatus(): array
    {
        return [
            self::MODE_SANDBOX => $this->getModeStatus(self::MODE_SANDBOX),
            self::MODE_PRODUCTION => $this->getModeStatus(self::MODE_PRODUCTION),
        ];
    }

    /** @return array{secretKey:string,proPriceId:string,teamPriceId:string,webhookSecret:string} */
    private function values(string $mode): array
    {
        if ($mode === self::MODE_SANDBOX) {
            return [
                'secretKey' => trim($this->sandboxSecretKey),
                'proPriceId' => trim($this->sandboxProPriceId),
                'teamPriceId' => trim($this->sandboxTeamPriceId),
                'webhookSecret' => trim($this->sandboxWebhookSecret),
            ];
        }

        return [
            'secretKey' => trim($this->productionSecretKey),
            'proPriceId' => trim($this->productionProPriceId),
            'teamPriceId' => trim($this->productionTeamPriceId),
            'webhookSecret' => trim($this->productionWebhookSecret),
        ];
    }

    /** @return array{configured:bool,masked:string} */
    private function describe(string $value, string $expectedPrefix): array
    {
        $value = trim($value);
        $configured = str_starts_with($value, $expectedPrefix);

        return [
            'configured' => $configured,
            'masked' => $configured ? $expectedPrefix . '••••••••' : '—',
        ];
    }
}
