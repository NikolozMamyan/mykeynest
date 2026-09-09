<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Repository\CredentialRepository;
use App\Service\CredentialCountProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class CredentialCountProviderTest extends TestCase
{
    public function testCountIsCachedAndCanBeInvalidated(): void
    {
        $user = $this->createUser(42);
        $repository = $this->createMock(CredentialRepository::class);
        $repository
            ->expects($this->exactly(2))
            ->method('countByUser')
            ->with($this->identicalTo($user))
            ->willReturnOnConsecutiveCalls(5, 6);

        $provider = new CredentialCountProvider(new ArrayAdapter(), $repository);

        self::assertSame(5, $provider->getForUser($user));
        self::assertSame(5, $provider->getForUser($user));

        $provider->invalidateForUser($user);

        self::assertSame(6, $provider->getForUser($user));
    }

    private function createUser(int $id): User
    {
        $user = (new User())
            ->setEmail('count-'.$id.'@example.test')
            ->setPassword('hashed')
            ->setCompany('MYKEYNEST');

        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);

        return $user;
    }
}
