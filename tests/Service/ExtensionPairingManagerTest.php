<?php

namespace App\Tests\Service;

use App\Entity\ExtensionClient;
use App\Entity\ExtensionPairingChallenge;
use App\Entity\User;
use App\Repository\ExtensionClientRepository;
use App\Repository\ExtensionPairingChallengeRepository;
use App\Repository\SubscriptionPlanConfigurationRepository;
use App\Repository\OrganizationMemberRepository;
use App\Service\ExtensionClientManager;
use App\Service\ExtensionInstallationChallengeManager;
use App\Service\ExtensionPairingManager;
use App\Service\MailerService;
use App\Service\SubscriptionPlanService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ExtensionPairingManagerTest extends TestCase
{
    public function testIssueExpiresPreviousOpenChallenge(): void
    {
        $user = $this->createUser();
        $previous = (new ExtensionPairingChallenge())
            ->setUser($user)
            ->setClientId('client-identifier-0001')
            ->setTokenHash(hash('sha256', 'previous'));

        $repository = $this->createMock(ExtensionPairingChallengeRepository::class);
        $repository
            ->expects(self::once())
            ->method('findOpenByUserAndClientId')
            ->with($user, 'client-identifier-0001')
            ->willReturn([$previous]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(ExtensionPairingChallenge::class));
        $entityManager->expects(self::once())->method('flush');

        $manager = new ExtensionPairingManager(
            $entityManager,
            $repository,
            $this->createExtensionClientManager($entityManager, $this->createMock(ExtensionClientRepository::class))
        );

        $result = $manager->issue($user, 'client-identifier-0001');

        self::assertSame(ExtensionPairingChallenge::STATUS_EXPIRED, $previous->getStatus());
        self::assertSame(ExtensionPairingChallenge::STATUS_PENDING, $result['challenge']->getStatus());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['plainToken']);
        self::assertSame(hash('sha256', $result['plainToken']), $result['challenge']->getTokenHash());
    }

    public function testExchangeProvisionsClientWithoutCompletingUserOnboarding(): void
    {
        $plainToken = str_repeat('a', 64);
        $clientId = 'client-identifier-0002';
        $user = $this->createUser();
        $challenge = (new ExtensionPairingChallenge())
            ->setUser($user)
            ->setClientId($clientId)
            ->setTokenHash(hash('sha256', $plainToken));

        $challengeRepository = $this->createMock(ExtensionPairingChallengeRepository::class);
        $challengeRepository
            ->expects(self::once())
            ->method('findByTokenHashForUpdate')
            ->with(hash('sha256', $plainToken))
            ->willReturn($challenge);

        $clientRepository = $this->createMock(ExtensionClientRepository::class);
        $clientRepository
            ->expects(self::once())
            ->method('findOneByUserAndClientId')
            ->with($user, $clientId)
            ->willReturn(null);
        $clientRepository
            ->expects(self::once())
            ->method('findByUserOrderByLastSeen')
            ->with($user)
            ->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback) => $callback());
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(ExtensionClient::class));
        $entityManager->expects(self::exactly(2))->method('flush');

        $request = Request::create('/api/extension-pairing/exchange', 'POST');
        $request->headers->set('X-Extension-Client-Id', $clientId);
        $request->headers->set('X-Browser-Name', 'Chrome');
        $request->headers->set('X-OS-Name', 'Windows');

        $manager = new ExtensionPairingManager(
            $entityManager,
            $challengeRepository,
            $this->createExtensionClientManager($entityManager, $clientRepository)
        );

        $result = $manager->exchange($plainToken, $request);

        self::assertSame(ExtensionPairingChallenge::STATUS_EXCHANGED, $challenge->getStatus());
        self::assertTrue($user->requiresExtensionOnboarding());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['apiToken']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['installationToken']);
    }

    public function testExchangeReconnectsAnAlreadyOnboardedUser(): void
    {
        $plainToken = str_repeat('b', 64);
        $clientId = 'client-identifier-0004';
        $user = $this->createUser()->completeExtensionOnboarding();
        $challenge = (new ExtensionPairingChallenge())
            ->setUser($user)
            ->setClientId($clientId)
            ->setTokenHash(hash('sha256', $plainToken));
        $existingClient = (new ExtensionClient())
            ->setUser($user)
            ->setClientId($clientId)
            ->setClientSecretHash(hash('sha256', 'old-installation-token'));

        $challengeRepository = $this->createMock(ExtensionPairingChallengeRepository::class);
        $challengeRepository
            ->expects(self::once())
            ->method('findByTokenHashForUpdate')
            ->with(hash('sha256', $plainToken))
            ->willReturn($challenge);

        $clientRepository = $this->createMock(ExtensionClientRepository::class);
        $clientRepository
            ->expects(self::once())
            ->method('findOneByUserAndClientId')
            ->with($user, $clientId)
            ->willReturn($existingClient);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback) => $callback());
        $entityManager->expects(self::exactly(2))->method('flush');

        $request = Request::create('/api/extension-pairing/exchange', 'POST');
        $request->headers->set('X-Extension-Client-Id', $clientId);
        $request->headers->set('X-Browser-Name', 'Chrome');
        $request->headers->set('X-OS-Name', 'Windows');

        $manager = new ExtensionPairingManager(
            $entityManager,
            $challengeRepository,
            $this->createExtensionClientManager($entityManager, $clientRepository)
        );

        $result = $manager->exchange($plainToken, $request);

        self::assertFalse($user->requiresExtensionOnboarding());
        self::assertSame(ExtensionPairingChallenge::STATUS_EXCHANGED, $challenge->getStatus());
        self::assertSame(hash('sha256', $result['installationToken']), $existingClient->getClientSecretHash());
    }

    public function testCompleteMarksUserOnboardedAndIsIdempotent(): void
    {
        $clientId = 'client-identifier-0003';
        $user = $this->createUser();
        $challenge = (new ExtensionPairingChallenge())
            ->setUser($user)
            ->setClientId($clientId)
            ->setTokenHash(hash('sha256', 'token'))
            ->setStatus(ExtensionPairingChallenge::STATUS_EXCHANGED)
            ->setExchangedAt(new \DateTimeImmutable());

        $repository = $this->createMock(ExtensionPairingChallengeRepository::class);
        $repository
            ->expects(self::exactly(2))
            ->method('findForCompletion')
            ->with($user, $challenge->getPublicId(), $clientId)
            ->willReturn($challenge);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback) => $callback());
        $entityManager
            ->expects(self::exactly(2))
            ->method('lock')
            ->with($challenge, LockMode::PESSIMISTIC_WRITE);
        $entityManager->expects(self::once())->method('flush');

        $manager = new ExtensionPairingManager(
            $entityManager,
            $repository,
            $this->createExtensionClientManager($entityManager, $this->createMock(ExtensionClientRepository::class))
        );

        $manager->complete($user, $challenge->getPublicId(), $clientId);
        $manager->complete($user, $challenge->getPublicId(), $clientId);

        self::assertSame(ExtensionPairingChallenge::STATUS_COMPLETED, $challenge->getStatus());
        self::assertFalse($user->requiresExtensionOnboarding());
        self::assertSame(User::EXTENSION_ONBOARDING_COMPLETED, $user->getExtensionOnboardingStatus());
    }

    private function createUser(): User
    {
        return (new User())
            ->setEmail('user@example.test')
            ->setCompany('Acme')
            ->setPassword('hashed')
            ->regenerateApiExtensionToken()
            ->requireExtensionOnboarding();
    }

    private function createExtensionClientManager(
        EntityManagerInterface $entityManager,
        ExtensionClientRepository $repository
    ): ExtensionClientManager {
        return new ExtensionClientManager(
            $entityManager,
            $repository,
            $this->createMock(ExtensionInstallationChallengeManager::class),
            $this->createMock(MailerService::class),
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createSubscriptionPlans($entityManager),
            'test'
        );
    }

    private function createSubscriptionPlans(EntityManagerInterface $entityManager): SubscriptionPlanService
    {
        $repository = $this->createMock(SubscriptionPlanConfigurationRepository::class);
        $repository->method('findByPlanCode')->willReturn(null);

        return new SubscriptionPlanService(
            $repository,
            $entityManager,
            $this->createMock(OrganizationMemberRepository::class),
        );
    }
}
