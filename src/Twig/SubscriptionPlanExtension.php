<?php

namespace App\Twig;

use App\Entity\User;
use App\Service\SubscriptionPlanService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SubscriptionPlanExtension extends AbstractExtension
{
    public function __construct(private readonly SubscriptionPlanService $plans)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('plan_has_feature', [$this, 'hasFeature']),
            new TwigFunction('plan_limit', [$this, 'getLimit']),
            new TwigFunction('subscription_plan', [$this->plans, 'getPlan']),
        ];
    }

    public function hasFeature(?User $user, string $feature): bool
    {
        return $user instanceof User && $this->plans->hasFeature($user, $feature);
    }

    public function getLimit(?User $user, string $limit): ?int
    {
        return $user instanceof User ? $this->plans->getLimit($user, $limit) : null;
    }
}
