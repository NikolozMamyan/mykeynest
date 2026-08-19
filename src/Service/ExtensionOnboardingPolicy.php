<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ExtensionOnboardingPolicy
{
    public function __construct(
        #[Autowire('%env(bool:EXTENSION_ONBOARDING_REQUIRED)%')]
        private bool $enabled
    ) {
    }

    public function initializeNewRegistration(User $user): void
    {
        if ($this->enabled) {
            $user->requireExtensionOnboarding();

            return;
        }

        $user->completeExtensionOnboarding();
    }

    public function isRequiredFor(User $user): bool
    {
        return $this->enabled && $user->requiresExtensionOnboarding();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
