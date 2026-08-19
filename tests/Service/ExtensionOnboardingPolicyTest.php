<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\ExtensionOnboardingPolicy;
use PHPUnit\Framework\TestCase;

final class ExtensionOnboardingPolicyTest extends TestCase
{
    public function testDisabledPolicyKeepsRegistrationBackwardCompatible(): void
    {
        $user = new User();
        $policy = new ExtensionOnboardingPolicy(false);

        $policy->initializeNewRegistration($user);

        self::assertFalse($policy->isRequiredFor($user));
        self::assertSame(User::EXTENSION_ONBOARDING_COMPLETED, $user->getExtensionOnboardingStatus());
    }

    public function testEnabledPolicyRequiresNewRegistrationOnboarding(): void
    {
        $user = (new User())->completeExtensionOnboarding();
        $policy = new ExtensionOnboardingPolicy(true);

        $policy->initializeNewRegistration($user);

        self::assertTrue($policy->isRequiredFor($user));
        self::assertSame(User::EXTENSION_ONBOARDING_PENDING, $user->getExtensionOnboardingStatus());
    }
}
