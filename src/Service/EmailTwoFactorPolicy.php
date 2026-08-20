<?php

namespace App\Service;

use App\Entity\User;

final class EmailTwoFactorPolicy
{
    public function __construct(private readonly SubscriptionPlanService $plans)
    {
    }

    public function isAvailableFor(User $user): bool
    {
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        return ($this->plans->getPlanForUser($user)['code'] ?? SubscriptionPlanService::PLAN_FREE)
            !== SubscriptionPlanService::PLAN_FREE;
    }

    public function isEnabledFor(User $user): bool
    {
        return $this->isAvailableFor($user) && $user->isEmailTwoFactorEnabled();
    }
}
