<?php

namespace App\Tests\Service;

use App\Entity\ExtensionInstallationChallenge;
use App\Entity\User;
use App\Repository\ExtensionInstallationChallengeRepository;
use App\Service\ExtensionInstallationChallengeManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ExtensionInstallationChallengeManagerTest extends TestCase
{
    public function testCreatingChallengeExpiresPreviousPendingChallengeForClient(): void
    {
        $user = $this->createUser();
        $previous = (new ExtensionInstallationChallenge())
            ->setUser($user)
            ->setRequestedClientId('new-browser')
            ->setTokenHash(hash('sha256', 'previous'));

        $repository = $this->createMock(ExtensionInstallationChallengeRepository::class);
        $repository
            ->expects(self::once())
            ->method('findBy')
            ->with([
                'user' => $user,
                'requestedClientId' => 'new-browser',
                'status' => ExtensionInstallationChallenge::STATUS_PENDING,
            ])
            ->willReturn([$previous]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(ExtensionInstallationChallenge::class));
        $entityManager->expects(self::once())->method('flush');

        $request = Request::create('/');
        $request->headers->set('X-Browser-Name', 'Firefox');
        $request->headers->set('User-Agent', 'Test browser');

        $manager = new ExtensionInstallationChallengeManager($entityManager, $repository);
        [$challenge, $plainToken] = $manager->createChallenge($user, $request, 'new-browser');

        self::assertSame(ExtensionInstallationChallenge::STATUS_EXPIRED, $previous->getStatus());
        self::assertSame(ExtensionInstallationChallenge::STATUS_PENDING, $challenge->getStatus());
        self::assertSame('new-browser', $challenge->getRequestedClientId());
        self::assertSame('Firefox', $challenge->getBrowserName());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $plainToken);
        self::assertSame(hash('sha256', $plainToken), $challenge->getTokenHash());
    }

    public function testExpireMarksPendingChallengeExpired(): void
    {
        $challenge = (new ExtensionInstallationChallenge())
            ->setUser($this->createUser())
            ->setRequestedClientId('new-browser')
            ->setTokenHash(hash('sha256', 'token'));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $manager = new ExtensionInstallationChallengeManager(
            $entityManager,
            $this->createMock(ExtensionInstallationChallengeRepository::class)
        );
        $manager->expire($challenge);

        self::assertSame(ExtensionInstallationChallenge::STATUS_EXPIRED, $challenge->getStatus());
    }

    private function createUser(): User
    {
        return (new User())
            ->setEmail('user@example.com')
            ->setPassword('hashed')
            ->setCompany('Acme');
    }
}
