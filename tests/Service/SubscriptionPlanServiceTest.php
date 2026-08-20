<?php

namespace App\Tests\Service;

use App\Entity\SubscriptionPlanConfiguration;
use App\Entity\User;
use App\Entity\UserSubscription;
use App\Entity\OrganizationMember;
use App\Repository\SubscriptionPlanConfigurationRepository;
use App\Repository\OrganizationMemberRepository;
use App\Service\SubscriptionPlanService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class SubscriptionPlanServiceTest extends TestCase
{
    public function testCurrentProductionDefaultsRemainBackwardCompatible(): void
    {
        $service = $this->createService();
        $freeUser = new User();
        $proUser = $this->createSubscribedUser('pro');
        $teamUser = $this->createSubscribedUser('team');

        self::assertSame(15, $service->getLimit($freeUser, SubscriptionPlanService::LIMIT_CREDENTIALS));
        self::assertSame(5, $service->getLimit($freeUser, SubscriptionPlanService::LIMIT_SHARES));
        self::assertSame(1, $service->getLimit($freeUser, SubscriptionPlanService::LIMIT_TEAMS));
        self::assertFalse($service->hasFeature($freeUser, SubscriptionPlanService::FEATURE_SECURE_NOTES));
        self::assertNull($service->getLimit($proUser, SubscriptionPlanService::LIMIT_CREDENTIALS));
        self::assertSame(1, $service->getLimit($proUser, SubscriptionPlanService::LIMIT_EXTENSION_INSTALLATIONS));
        self::assertSame(5, $service->getLimit($teamUser, SubscriptionPlanService::LIMIT_EXTENSION_INSTALLATIONS));
    }

    public function testStripeSeatQuantityDoesNotChangePerMemberInstallationLimit(): void
    {
        $service = $this->createService();
        $teamUser = $this->createSubscribedUser('team');
        $teamUser->getUserSubscription()
            ->setStripePriceId('price_team')
            ->setQuantity(8);

        self::assertSame(5, $service->getLimit(
            $teamUser,
            SubscriptionPlanService::LIMIT_EXTENSION_INSTALLATIONS,
        ));
    }

    public function testActiveCompanyMemberGetsAllEffectiveTeamEntitlements(): void
    {
        $repository = $this->createMock(SubscriptionPlanConfigurationRepository::class);
        $repository->method('findByPlanCode')->willReturn(null);
        $organizationMembers = $this->createMock(OrganizationMemberRepository::class);
        $organizationMembers->method('findActiveTeamMembership')->willReturn(new OrganizationMember());
        $service = new SubscriptionPlanService(
            $repository,
            $this->createMock(EntityManagerInterface::class),
            $organizationMembers,
        );
        $user = new User();

        self::assertSame(SubscriptionPlanService::PLAN_TEAM, $service->getPlanForUser($user)['code']);
        self::assertSame(5, $service->getLimit($user, SubscriptionPlanService::LIMIT_EXTENSION_INSTALLATIONS));
        self::assertNull($service->getLimit($user, SubscriptionPlanService::LIMIT_CREDENTIALS));
        self::assertNull($service->getLimit($user, SubscriptionPlanService::LIMIT_SHARES));
        self::assertNull($service->getLimit($user, SubscriptionPlanService::LIMIT_TEAMS));
        self::assertSame(1, $service->getPersonalLimit($user, SubscriptionPlanService::LIMIT_TEAMS));
        self::assertTrue($service->hasFeature($user, SubscriptionPlanService::FEATURE_SECURE_NOTES));
        self::assertTrue($service->hasFeature($user, SubscriptionPlanService::FEATURE_SECURITY_CHECKER));
        self::assertTrue($service->hasFeature($user, SubscriptionPlanService::FEATURE_CREDENTIAL_IMPORT));
    }

    public function testStoredFreeConfigurationOverridesOnlyConfiguredRules(): void
    {
        $configuration = (new SubscriptionPlanConfiguration())
            ->setPlanCode('free')
            ->setLimits([
                SubscriptionPlanService::LIMIT_CREDENTIALS => null,
                SubscriptionPlanService::LIMIT_SHARES => 8,
            ])
            ->setFeatures([
                SubscriptionPlanService::FEATURE_SECURE_NOTES => true,
            ]);

        $service = $this->createService($configuration);
        $user = new User();

        self::assertNull($service->getLimit($user, SubscriptionPlanService::LIMIT_CREDENTIALS));
        self::assertSame(8, $service->getLimit($user, SubscriptionPlanService::LIMIT_SHARES));
        self::assertSame(1, $service->getLimit($user, SubscriptionPlanService::LIMIT_TEAMS));
        self::assertTrue($service->hasFeature($user, SubscriptionPlanService::FEATURE_SECURE_NOTES));
        self::assertTrue($service->hasFeature($user, SubscriptionPlanService::FEATURE_PASSWORD_GENERATOR));
    }

    public function testCompanyTeamSeatOverridesAPersonalProPlan(): void
    {
        $repository = $this->createMock(SubscriptionPlanConfigurationRepository::class);
        $repository->method('findByPlanCode')->willReturn(null);
        $organizationMembers = $this->createMock(OrganizationMemberRepository::class);
        $organizationMembers->method('findActiveTeamMembership')->willReturn(new OrganizationMember());
        $service = new SubscriptionPlanService(
            $repository,
            $this->createMock(EntityManagerInterface::class),
            $organizationMembers,
        );

        self::assertSame(
            SubscriptionPlanService::PLAN_TEAM,
            $service->getPlanForUser($this->createSubscribedUser(SubscriptionPlanService::PLAN_PRO))['code'],
        );
    }

    public function testUpdateFreePlanPersistsValidatedValuesAndUnlimitedLimits(): void
    {
        $repository = $this->createMock(SubscriptionPlanConfigurationRepository::class);
        $repository->method('findByPlanCode')->with('free')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (SubscriptionPlanConfiguration $configuration): bool {
                return $configuration->getPlanCode() === 'free'
                    && $configuration->getLimits()[SubscriptionPlanService::LIMIT_CREDENTIALS] === null
                    && $configuration->getLimits()[SubscriptionPlanService::LIMIT_SHARES] === 7
                    && $configuration->getFeatures()[SubscriptionPlanService::FEATURE_SECURE_NOTES] === true
                    && $configuration->getFeatures()[SubscriptionPlanService::FEATURE_CREDENTIAL_IMPORT] === false;
            }));
        $entityManager->expects(self::once())->method('flush');

        $organizationMembers = $this->createMock(OrganizationMemberRepository::class);
        $organizationMembers->method('findActiveTeamMembership')->willReturn(null);
        $service = new SubscriptionPlanService($repository, $entityManager, $organizationMembers);
        $configuration = $service->updateFreePlan([
            'credentialLimit' => null,
            'credentialsUnlimited' => true,
            'shareLimit' => 7,
            'sharesUnlimited' => false,
            'teamLimit' => 2,
            'teamsUnlimited' => false,
            'extensionInstallationLimit' => 1,
            'extensionInstallationsUnlimited' => false,
            'passwordGenerator' => true,
            'secureNotes' => true,
            'securityChecker' => false,
            'credentialImport' => false,
        ]);

        self::assertNull($configuration->getLimits()[SubscriptionPlanService::LIMIT_CREDENTIALS]);
        self::assertSame(7, $configuration->getLimits()[SubscriptionPlanService::LIMIT_SHARES]);
        self::assertTrue($configuration->getFeatures()[SubscriptionPlanService::FEATURE_SECURE_NOTES]);
    }

    private function createService(?SubscriptionPlanConfiguration $configuration = null): SubscriptionPlanService
    {
        $repository = $this->createMock(SubscriptionPlanConfigurationRepository::class);
        $repository
            ->method('findByPlanCode')
            ->willReturnCallback(static fn (string $code): ?SubscriptionPlanConfiguration => $code === 'free' ? $configuration : null);

        $organizationMembers = $this->createMock(OrganizationMemberRepository::class);
        $organizationMembers->method('findActiveTeamMembership')->willReturn(null);

        return new SubscriptionPlanService(
            $repository,
            $this->createMock(EntityManagerInterface::class),
            $organizationMembers,
        );
    }

    private function createSubscribedUser(string $planCode): User
    {
        $subscription = (new UserSubscription())
            ->setPlanCode($planCode)
            ->setIsActive(true);

        return (new User())->setUserSubscription($subscription);
    }
}
