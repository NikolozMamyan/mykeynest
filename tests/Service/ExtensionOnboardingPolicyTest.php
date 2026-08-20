<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\ExtensionOnboardingPolicy;
use App\Repository\SubscriptionPlanConfigurationRepository;
use App\Repository\OrganizationMemberRepository;
use App\Service\SubscriptionPlanService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ExtensionOnboardingPolicyTest extends TestCase
{
    public function testDisabledPolicyKeepsRegistrationBackwardCompatible(): void
    {
        $user = new User();
        $policy = new ExtensionOnboardingPolicy(false, $this->createSubscriptionPlans());

        $policy->initializeNewRegistration($user);

        self::assertFalse($policy->isRequiredFor($user));
        self::assertSame(User::EXTENSION_ONBOARDING_COMPLETED, $user->getExtensionOnboardingStatus());
    }

    public function testEnabledPolicyRequiresNewRegistrationOnboarding(): void
    {
        $user = (new User())->completeExtensionOnboarding();
        $policy = new ExtensionOnboardingPolicy(true, $this->createSubscriptionPlans());

        $policy->initializeNewRegistration($user);

        self::assertTrue($policy->isRequiredFor($user));
        self::assertSame(User::EXTENSION_ONBOARDING_PENDING, $user->getExtensionOnboardingStatus());
    }

    private function createSubscriptionPlans(): SubscriptionPlanService
    {
        $repository = $this->createMock(SubscriptionPlanConfigurationRepository::class);
        $repository->method('findByPlanCode')->willReturn(null);

        return new SubscriptionPlanService(
            $repository,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(OrganizationMemberRepository::class),
        );
    }
}
