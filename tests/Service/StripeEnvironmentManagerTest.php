<?php

namespace App\Tests\Service;

use App\Entity\StripePaymentConfiguration;
use App\Repository\StripePaymentConfigurationRepository;
use App\Service\StripeEnvironmentManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class StripeEnvironmentManagerTest extends TestCase
{
    public function testSandboxVariablesAreLoadedWithoutExposingSecrets(): void
    {
        $repository = $this->createMock(StripePaymentConfigurationRepository::class);
        $repository->method('findConfiguration')->willReturn(null);
        $manager = $this->createManager($repository, $this->createMock(EntityManagerInterface::class));

        self::assertSame('sandbox', $manager->getActiveMode());
        self::assertTrue($manager->isReady('sandbox'));
        self::assertFalse($manager->isReady('production'));
        self::assertSame('sk_test_••••••••', $manager->getModeStatus('sandbox')['secretKey']['masked']);
        self::assertStringNotContainsString('sandbox-secret', $manager->getModeStatus('sandbox')['secretKey']['masked']);
    }

    public function testIncompleteModeCannotBeActivated(): void
    {
        $repository = $this->createMock(StripePaymentConfigurationRepository::class);
        $repository->method('findConfiguration')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $manager = $this->createManager($repository, $entityManager);

        $this->expectException(\LogicException::class);
        $manager->activateMode('production', 'admin@example.com');
    }

    public function testReadyProductionModeIsPersistedWithAuditIdentity(): void
    {
        $repository = $this->createMock(StripePaymentConfigurationRepository::class);
        $repository->method('findConfiguration')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(static fn (StripePaymentConfiguration $configuration): bool =>
                $configuration->getActiveMode() === 'production'
                && $configuration->getUpdatedBy() === 'admin@example.com'
            ));
        $entityManager->expects(self::once())->method('flush');

        $manager = $this->createManager($repository, $entityManager, withProduction: true);
        $configuration = $manager->activateMode('production', 'admin@example.com');

        self::assertSame('production', $configuration->getActiveMode());
    }

    private function createManager(
        StripePaymentConfigurationRepository $repository,
        EntityManagerInterface $entityManager,
        bool $withProduction = false,
    ): StripeEnvironmentManager {
        return new StripeEnvironmentManager(
            $repository,
            $entityManager,
            'sk_test_sandbox-secret',
            'price_sandbox_pro',
            'price_sandbox_team',
            'whsec_sandbox',
            $withProduction ? 'sk_live_production-secret' : '',
            $withProduction ? 'price_production_pro' : '',
            $withProduction ? 'price_production_team' : '',
            $withProduction ? 'whsec_production' : '',
        );
    }
}
