<?php

namespace App\Tests\Service;

use App\Entity\ExtensionClient;
use App\Entity\ExtensionInstallationChallenge;
use App\Entity\User;
use App\Entity\UserSubscription;
use App\Repository\ExtensionClientRepository;
use App\Service\ExtensionClientManager;
use App\Service\ExtensionInstallationChallengeManager;
use App\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ExtensionClientManagerTest extends TestCase
{
    public function testDeleteRemovesInstallationAndFlushesImmediately(): void
    {
        $user = $this->createTeamUser();
        $client = $this->createClient($user, 'browser-to-delete');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('remove')->with($client);
        $entityManager->expects(self::once())->method('flush');

        $manager = $this->createManager(
            $entityManager,
            $this->createMock(ExtensionClientRepository::class),
            $this->createMock(ExtensionInstallationChallengeManager::class),
            $this->createMock(MailerService::class),
            'prod'
        );

        $manager->delete($client);
    }

    public function testThirdTeamInstallationRequiresEmailApproval(): void
    {
        $user = $this->createTeamUser();
        $challenge = $this->createChallenge($user, 'third-browser');
        $repository = $this->createRepository($user, [
            $this->createClient($user, 'first-browser'),
            $this->createClient($user, 'second-browser'),
        ]);

        $challengeManager = $this->createMock(ExtensionInstallationChallengeManager::class);
        $challengeManager->method('findLatestByUserAndClientId')->willReturn(null);
        $challengeManager
            ->expects(self::once())
            ->method('createChallenge')
            ->willReturn([$challenge, 'plain-token']);

        $mailer = $this->createMock(MailerService::class);
        $mailer->expects(self::once())->method('send');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');

        $manager = $this->createManager(
            $entityManager,
            $repository,
            $challengeManager,
            $mailer,
            'prod'
        );

        $result = $manager->resolveFromRequest($user, $this->createRequest('third-browser'));

        self::assertSame('approval_required', $result['status']);
        self::assertSame('email', $result['delivery']);
        self::assertSame($challenge, $result['challenge']);
        self::assertArrayNotHasKey('developmentApproveUrl', $result);
    }

    public function testProductionEmailFailureExpiresChallengeAndReportsDeliveryFailure(): void
    {
        $user = $this->createTeamUser();
        $challenge = $this->createChallenge($user, 'second-browser');
        $repository = $this->createRepository($user, [
            $this->createClient($user, 'first-browser'),
        ]);

        $challengeManager = $this->createMock(ExtensionInstallationChallengeManager::class);
        $challengeManager->method('findLatestByUserAndClientId')->willReturn(null);
        $challengeManager->method('createChallenge')->willReturn([$challenge, 'plain-token']);
        $challengeManager
            ->expects(self::once())
            ->method('expire')
            ->with($challenge)
            ->willReturnCallback(static function (ExtensionInstallationChallenge $expiredChallenge): void {
                $expiredChallenge->setStatus(ExtensionInstallationChallenge::STATUS_EXPIRED);
            });

        $mailer = $this->createMock(MailerService::class);
        $mailer
            ->expects(self::once())
            ->method('send')
            ->willThrowException(new \RuntimeException('SMTP unavailable'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $manager = $this->createManager(
            $this->createMock(EntityManagerInterface::class),
            $repository,
            $challengeManager,
            $mailer,
            'prod',
            $logger
        );

        $result = $manager->resolveFromRequest($user, $this->createRequest('second-browser'));

        self::assertSame('delivery_failed', $result['status']);
        self::assertSame(ExtensionInstallationChallenge::STATUS_EXPIRED, $challenge->getStatus());
    }

    public function testDevelopmentEmailFailureReturnsTemporaryApprovalUrl(): void
    {
        $user = $this->createTeamUser();
        $challenge = $this->createChallenge($user, 'second-browser');
        $repository = $this->createRepository($user, [
            $this->createClient($user, 'first-browser'),
        ]);

        $challengeManager = $this->createMock(ExtensionInstallationChallengeManager::class);
        $challengeManager->method('findLatestByUserAndClientId')->willReturn(null);
        $challengeManager->method('createChallenge')->willReturn([$challenge, 'plain-token']);
        $challengeManager->expects(self::never())->method('expire');

        $mailer = $this->createMock(MailerService::class);
        $mailer
            ->expects(self::once())
            ->method('send')
            ->willThrowException(new \RuntimeException('SMTP unavailable'));

        $manager = $this->createManager(
            $this->createMock(EntityManagerInterface::class),
            $repository,
            $challengeManager,
            $mailer,
            'dev'
        );

        $result = $manager->resolveFromRequest($user, $this->createRequest('second-browser'));

        self::assertSame('approval_required', $result['status']);
        self::assertSame('development_url', $result['delivery']);
        self::assertSame('https://app.test/approve', $result['developmentApproveUrl']);
        self::assertSame(ExtensionInstallationChallenge::STATUS_PENDING, $challenge->getStatus());
    }

    private function createManager(
        EntityManagerInterface $entityManager,
        ExtensionClientRepository $repository,
        ExtensionInstallationChallengeManager $challengeManager,
        MailerService $mailer,
        string $environment,
        ?LoggerInterface $logger = null
    ): ExtensionClientManager {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator
            ->method('generate')
            ->willReturnMap([
                [
                    'api_extension_installation_challenge_approve',
                    ['token' => 'plain-token'],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                    'https://app.test/approve',
                ],
                [
                    'api_extension_installation_challenge_reject',
                    ['token' => 'plain-token'],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                    'https://app.test/reject',
                ],
            ]);

        return new ExtensionClientManager(
            $entityManager,
            $repository,
            $challengeManager,
            $mailer,
            $urlGenerator,
            $logger ?? $this->createMock(LoggerInterface::class),
            $environment
        );
    }

    /**
     * @param ExtensionClient[] $clients
     */
    private function createRepository(User $user, array $clients): ExtensionClientRepository
    {
        $repository = $this->createMock(ExtensionClientRepository::class);
        $repository
            ->method('findByUserOrderByLastSeen')
            ->with($user)
            ->willReturn($clients);
        $repository
            ->method('findOneByUserAndClientId')
            ->with($user)
            ->willReturn(null);
        $repository
            ->method('findBlockedByUser')
            ->with($user)
            ->willReturn([]);

        return $repository;
    }

    private function createTeamUser(): User
    {
        $user = (new User())
            ->setEmail('team@example.com')
            ->setPassword('hashed')
            ->setCompany('Acme');

        $subscription = (new UserSubscription())
            ->setPlanCode('TEAM')
            ->setStatus('active')
            ->setIsActive(true);

        return $user->setUserSubscription($subscription);
    }

    private function createClient(User $user, string $clientId): ExtensionClient
    {
        return (new ExtensionClient())
            ->setUser($user)
            ->setClientId($clientId)
            ->setClientSecretHash(hash('sha256', $clientId));
    }

    private function createChallenge(User $user, string $clientId): ExtensionInstallationChallenge
    {
        return (new ExtensionInstallationChallenge())
            ->setUser($user)
            ->setRequestedClientId($clientId)
            ->setTokenHash(hash('sha256', 'plain-token'));
    }

    private function createRequest(string $clientId): Request
    {
        $request = Request::create('/extention/api/credentials/list');
        $request->headers->set('X-Extension-Client-Id', $clientId);
        $request->headers->set('X-Browser-Name', 'Firefox');
        $request->headers->set('X-OS-Name', 'Windows');

        return $request;
    }
}
