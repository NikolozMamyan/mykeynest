<?php

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserExtensionOnboardingTest extends TestCase
{
    public function testNewUserRequiresExtensionOnboarding(): void
    {
        $user = new User();

        self::assertTrue($user->requiresExtensionOnboarding());
        self::assertSame(User::EXTENSION_ONBOARDING_PENDING, $user->getExtensionOnboardingStatus());
        self::assertNull($user->getExtensionOnboardedAt());
    }

    public function testOnboardingCanBeCompletedOrDeferred(): void
    {
        $user = new User();

        $user->completeExtensionOnboarding();
        self::assertFalse($user->requiresExtensionOnboarding());
        self::assertSame(User::EXTENSION_ONBOARDING_COMPLETED, $user->getExtensionOnboardingStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $user->getExtensionOnboardedAt());

        $user->requireExtensionOnboarding();
        $user->deferExtensionOnboarding();
        self::assertFalse($user->requiresExtensionOnboarding());
        self::assertSame(User::EXTENSION_ONBOARDING_DEFERRED, $user->getExtensionOnboardingStatus());
        self::assertNull($user->getExtensionOnboardedAt());
    }
}
