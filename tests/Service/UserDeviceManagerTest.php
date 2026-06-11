<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\UserDevice;
use App\Repository\UserDeviceRepository;
use App\Service\UserDeviceManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class UserDeviceManagerTest extends TestCase
{
    public function testTrustCreatesIndependentDeviceRecord(): void
    {
        $user = $this->createUser();
        $deviceId = str_repeat('a', 64);
        $repository = $this->createMock(UserDeviceRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist')->with(self::isInstanceOf(UserDevice::class));
        $entityManager->expects($this->exactly(2))->method('flush');

        $request = Request::create('/');
        $request->headers->set('User-Agent', 'Test browser');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $manager = new UserDeviceManager($entityManager, $repository, $requestStack);
        $device = $manager->trust($user, $deviceId, 'Laptop');

        self::assertSame($user, $device->getUser());
        self::assertSame($deviceId, $device->getDeviceId());
        self::assertSame('Laptop', $device->getDeviceName());
        self::assertTrue($device->isTrusted());
    }

    public function testBlockedDeviceIsNeverTrusted(): void
    {
        $user = $this->createUser();
        $device = (new UserDevice())
            ->setUser($user)
            ->setDeviceId(str_repeat('b', 64))
            ->setTrustedAt(new \DateTimeImmutable('-1 day'))
            ->setTrustExpiresAt(new \DateTimeImmutable('+1 day'))
            ->setBlockedAt(new \DateTimeImmutable())
            ->setBlockedReason('suspicious');

        $repository = $this->createMock(UserDeviceRepository::class);
        $repository->method('findOneBy')->willReturn($device);

        $manager = new UserDeviceManager(
            $this->createMock(EntityManagerInterface::class),
            $repository,
            new RequestStack()
        );

        self::assertSame(
            UserDeviceManager::STATE_BLOCKED,
            $manager->getAccessState($user, (string) $device->getDeviceId())
        );
    }

    public function testRevokeAllTrustKeepsCurrentDeviceOnly(): void
    {
        $user = $this->createUser();
        $currentDevice = $this->createTrustedDevice($user, str_repeat('c', 64));
        $remoteDevice = $this->createTrustedDevice($user, str_repeat('d', 64));

        $repository = $this->createMock(UserDeviceRepository::class);
        $repository->method('findBy')->with(['user' => $user])->willReturn([$currentDevice, $remoteDevice]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $manager = new UserDeviceManager($entityManager, $repository, new RequestStack());
        $count = $manager->revokeAllTrust($user, (string) $currentDevice->getDeviceId());

        self::assertSame(1, $count);
        self::assertNull($currentDevice->getRevokedAt());
        self::assertSame('logout_all', $remoteDevice->getRevokedReason());
        self::assertFalse($remoteDevice->isTrusted());
    }

    private function createUser(): User
    {
        return (new User())
            ->setEmail('user@example.com')
            ->setPassword('hashed')
            ->setCompany('Acme');
    }

    private function createTrustedDevice(User $user, string $deviceId): UserDevice
    {
        return (new UserDevice())
            ->setUser($user)
            ->setDeviceId($deviceId)
            ->setTrustedAt(new \DateTimeImmutable('-1 day'))
            ->setTrustExpiresAt(new \DateTimeImmutable('+1 day'));
    }
}
