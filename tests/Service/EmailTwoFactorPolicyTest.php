<?php

namespace App\Tests\Service;

use App\Entity\OrganizationMember;
use App\Entity\User;
use App\Entity\UserSubscription;
use App\Repository\OrganizationMemberRepository;
use App\Repository\SubscriptionPlanConfigurationRepository;
use App\Service\EmailTwoFactorPolicy;
use App\Service\SubscriptionPlanService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class EmailTwoFactorPolicyTest extends TestCase
{
    public function testFreePlanCannotEnableEmailTwoFactorAuthentication(): void
    {
        $policy = new EmailTwoFactorPolicy($this->createPlanService());
        $user = new User();

        self::assertTrue($user->isEmailTwoFactorEnabled());
        self::assertFalse($policy->isAvailableFor($user));
        self::assertFalse($policy->isEnabledFor($user));
    }

    public function testProUserCanControlEmailTwoFactorAuthentication(): void
    {
        $policy = new EmailTwoFactorPolicy($this->createPlanService());
        $user = (new User())->setUserSubscription(
            (new UserSubscription())
                ->setPlanCode(SubscriptionPlanService::PLAN_PRO)
                ->setIsActive(true),
        );

        self::assertTrue($policy->isAvailableFor($user));
        self::assertTrue($policy->isEnabledFor($user));

        $user->setEmailTwoFactorEnabled(false);

        self::assertFalse($policy->isEnabledFor($user));
    }

    public function testActiveTeamSeatUnlocksEmailTwoFactorAuthentication(): void
    {
        $policy = new EmailTwoFactorPolicy($this->createPlanService(new OrganizationMember()));
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 1);

        self::assertTrue($policy->isAvailableFor($user));
        self::assertTrue($policy->isEnabledFor($user));
    }

    public function testAdministratorKeepsEmailTwoFactorAuthenticationWithoutSubscription(): void
    {
        $policy = new EmailTwoFactorPolicy($this->createPlanService());
        $user = (new User())->setRoles(['ROLE_ADMIN']);

        self::assertTrue($policy->isAvailableFor($user));
        self::assertTrue($policy->isEnabledFor($user));
    }

    private function createPlanService(?OrganizationMember $teamMembership = null): SubscriptionPlanService
    {
        $plans = $this->createMock(SubscriptionPlanConfigurationRepository::class);
        $plans->method('findByPlanCode')->willReturn(null);

        $organizationMembers = $this->createMock(OrganizationMemberRepository::class);
        $organizationMembers->method('findActiveTeamMembership')->willReturn($teamMembership);

        return new SubscriptionPlanService(
            $plans,
            $this->createMock(EntityManagerInterface::class),
            $organizationMembers,
        );
    }
}
