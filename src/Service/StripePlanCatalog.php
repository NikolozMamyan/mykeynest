<?php

namespace App\Service;

final class StripePlanCatalog
{
    public function __construct(
        private readonly int $teamMinimumSeats = 6,
        private readonly int $teamMaximumSeats = 250,
    ) {
    }

    public function normalizePaidPlan(string $planCode): string
    {
        $planCode = mb_strtolower(trim($planCode));

        if (!in_array($planCode, [SubscriptionPlanService::PLAN_PRO, SubscriptionPlanService::PLAN_TEAM], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported Stripe plan "%s".', $planCode));
        }

        return $planCode;
    }

    /**
     * @return array{price:string, quantity:int, adjustable_quantity?:array{enabled:bool, minimum:int, maximum:int}}
     */
    public function getCheckoutLineItem(string $planCode, string $priceId): array
    {
        $planCode = $this->normalizePaidPlan($planCode);
        if (!str_starts_with(trim($priceId), 'price_')) {
            throw new \LogicException(sprintf('Stripe Price is not configured for the %s plan.', $planCode));
        }

        $lineItem = [
            'price' => trim($priceId),
            'quantity' => $planCode === SubscriptionPlanService::PLAN_TEAM ? $this->getTeamMinimumSeats() : 1,
        ];

        if ($planCode === SubscriptionPlanService::PLAN_TEAM) {
            $lineItem['adjustable_quantity'] = [
                'enabled' => true,
                'minimum' => $this->getTeamMinimumSeats(),
                'maximum' => max($this->getTeamMinimumSeats(), $this->teamMaximumSeats),
            ];
        }

        return $lineItem;
    }

    public function resolvePlanFromPrice(?string $priceId, string $proPriceId, string $teamPriceId): ?string
    {
        $priceId = trim((string) $priceId);
        if ($priceId === '') {
            return null;
        }

        $proPriceId = trim($proPriceId);
        $teamPriceId = trim($teamPriceId);

        if ($proPriceId !== '' && hash_equals($proPriceId, $priceId)) {
            return SubscriptionPlanService::PLAN_PRO;
        }

        if ($teamPriceId !== '' && hash_equals($teamPriceId, $priceId)) {
            return SubscriptionPlanService::PLAN_TEAM;
        }

        return null;
    }

    public function getTeamMinimumSeats(): int
    {
        return max(6, $this->teamMinimumSeats);
    }

    public function getExpectedMonthlyAmount(string $planCode): int
    {
        return $this->normalizePaidPlan($planCode) === SubscriptionPlanService::PLAN_TEAM ? 549 : 699;
    }
}
