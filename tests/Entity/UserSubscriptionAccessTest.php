<?php

namespace App\Tests\Entity;

use App\Entity\User;
use App\Entity\UserSubscription;
use PHPUnit\Framework\TestCase;

final class UserSubscriptionAccessTest extends TestCase
{
    public function testActivePlanComparisonIsNormalized(): void
    {
        $user = (new User())
            ->setEmail('team@example.com')
            ->setPassword('hashed')
            ->setCompany('Acme')
            ->setUserSubscription(
                (new UserSubscription())
                    ->setPlanCode(' TEAM ')
                    ->setStatus('active')
                    ->setIsActive(true)
            );

        self::assertTrue($user->hasActivePlan('team'));
        self::assertTrue($user->hasActivePlan('TEAM'));
        self::assertFalse($user->hasActivePlan('pro'));
    }

    public function testInactiveSubscriptionDoesNotGrantPlanAccess(): void
    {
        $user = (new User())
            ->setEmail('team@example.com')
            ->setPassword('hashed')
            ->setCompany('Acme')
            ->setUserSubscription(
                (new UserSubscription())
                    ->setPlanCode('team')
                    ->setStatus('canceled')
                    ->setIsActive(false)
            );

        self::assertFalse($user->hasActivePlan('team'));
    }
}
