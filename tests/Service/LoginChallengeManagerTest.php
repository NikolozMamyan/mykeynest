<?php

namespace App\Tests\Service;

use App\Entity\LoginChallenge;
use App\Entity\User;
use App\Repository\LoginChallengeRepository;
use App\Service\LoginChallengeManager;
use App\Service\UserDeviceManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class LoginChallengeManagerTest extends TestCase
{
    public function testCreatingChallengeExpiresPreviousPendingChallengeForDevice(): void
    {
        $user = $this->createUser();
        $deviceId = str_repeat('f', 64);
        $previous = (new LoginChallenge())
            ->setUser($user)
            ->setDeviceId($deviceId)
            ->setTokenHash(hash('sha256', 'previous'));

        $repository = $this->createMock(LoginChallengeRepository::class);
        $repository
            ->expects($this->once())
            ->method('findBy')
            ->with([
                'user' => $user,
                'deviceId' => $deviceId,
                'status' => LoginChallenge::STATUS_PENDING,
            ])
            ->willReturn([$previous]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist')->with(self::isInstanceOf(LoginChallenge::class));
        $entityManager->expects($this->once())->method('flush');

        $userDeviceManager = $this->createMock(UserDeviceManager::class);
        $userDeviceManager
            ->expects($this->once())
            ->method('remember')
            ->with($user, $deviceId, 'Laptop');

        $request = Request::create('/');
        $request->headers->set('User-Agent', 'Test browser');
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $manager = new LoginChallengeManager(
            $entityManager,
            $repository,
            $requestStack,
            $userDeviceManager
        );

        [$challenge, $plainToken] = $manager->createChallenge($user, $deviceId, 'Laptop');

        self::assertSame(LoginChallenge::STATUS_EXPIRED, $previous->getStatus());
        self::assertSame(LoginChallenge::STATUS_PENDING, $challenge->getStatus());
        self::assertSame($deviceId, $challenge->getDeviceId());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $plainToken);
        self::assertSame(hash('sha256', $plainToken), $challenge->getTokenHash());
    }

    public function testExpiredPendingChallengeIsPersistentlyMarkedExpired(): void
    {
        $challenge = (new LoginChallenge())
            ->setUser($this->createUser())
            ->setDeviceId(str_repeat('a', 64))
            ->setTokenHash(hash('sha256', 'token'))
            ->setExpiresAt(new \DateTimeImmutable('-1 minute'));

        $repository = $this->createMock(LoginChallengeRepository::class);
        $repository->method('findOneBy')->willReturn($challenge);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $manager = new LoginChallengeManager(
            $entityManager,
            $repository,
            new RequestStack(),
            $this->createMock(UserDeviceManager::class)
        );

        self::assertNull($manager->findValidByPlainToken('token'));
        self::assertSame(LoginChallenge::STATUS_EXPIRED, $challenge->getStatus());
    }

    public function testPendingChallengeCannotBeClaimedForCompletion(): void
    {
        $repository = $this->createMock(LoginChallengeRepository::class);
        $repository->expects($this->never())->method('claimApproved');

        $manager = new LoginChallengeManager(
            $this->createMock(EntityManagerInterface::class),
            $repository,
            new RequestStack(),
            $this->createMock(UserDeviceManager::class)
        );

        self::assertFalse($manager->claimForCompletion(new LoginChallenge()));
    }

    public function testApprovedChallengeIsClaimedOnlyThroughAtomicRepositoryUpdate(): void
    {
        $challenge = (new LoginChallenge())
            ->setUser($this->createUser())
            ->setDeviceId(str_repeat('b', 64))
            ->setTokenHash(hash('sha256', 'approved'))
            ->setStatus(LoginChallenge::STATUS_APPROVED);

        $repository = $this->createMock(LoginChallengeRepository::class);
        $repository
            ->expects($this->once())
            ->method('claimApproved')
            ->with($challenge, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(true);

        $manager = new LoginChallengeManager(
            $this->createMock(EntityManagerInterface::class),
            $repository,
            new RequestStack(),
            $this->createMock(UserDeviceManager::class)
        );

        self::assertTrue($manager->claimForCompletion($challenge));
        self::assertSame(LoginChallenge::STATUS_COMPLETED, $challenge->getStatus());
        self::assertNotNull($challenge->getCompletedAt());
    }

    private function createUser(): User
    {
        return (new User())
            ->setEmail('user@example.com')
            ->setPassword('hashed')
            ->setCompany('Acme');
    }
}
