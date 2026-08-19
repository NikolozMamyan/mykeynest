<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ExtensionOnboardingPolicy
{
    public function __construct(
        #[Autowire('%env(bool:EXTENSION_ONBOARDING_REQUIRED)%')]
        private bool $enabled,
        private SubscriptionPlanService $subscriptionPlans,
    ) {
    }

    public function initializeNewRegistration(User $user): void
    {
        if ($this->enabled && $this->isExtensionAvailableFor($user)) {
            $user->requireExtensionOnboarding();

            return;
        }

        $user->completeExtensionOnboarding();
    }

    public function isRequiredFor(User $user): bool
    {
        return $this->enabled
            && $this->isExtensionAvailableFor($user)
            && $user->requiresExtensionOnboarding();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    private function isExtensionAvailableFor(User $user): bool
    {
        return $this->subscriptionPlans->getLimit(
            $user,
            SubscriptionPlanService::LIMIT_EXTENSION_INSTALLATIONS
        ) !== 0;
    }
}
