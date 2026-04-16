<?php

namespace App\Tests\Service;

use App\Entity\Credential;
use App\Entity\DraftPassword;
use App\Entity\User;
use App\Repository\CredentialRepository;
use App\Repository\DraftPasswordRepository;
use App\Service\CredentialEncryptionRotationService;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class CredentialEncryptionRotationServiceTest extends TestCase
{
    public function testRotateUserRewritesOwnedSecretsWithNewKey(): void
    {
        $user = (new User())
            ->setEmail('owner@example.com')
            ->setPassword('hashed')
            ->setCompany('Acme')
            ->setCredentialEncryptionKey(str_repeat('a', 64));

        $credential = (new Credential())
            ->setName('Github')
            ->setDomain('github.com')
            ->setUsername('owner')
            ->setUser($user);

        $draftPassword = (new DraftPassword())
            ->setUser($user)
            ->setName('draft');

        $encryptionService = new EncryptionService();
        $encryptionService->setKeyFromUserSecret((string) $user->getCredentialEncryptionKey());
        $credential->setPassword($encryptionService->encrypt('top-secret'));
        $draftPassword->setPassword($encryptionService->encrypt('draft-secret'));

        $credentialRepository = $this->createMock(CredentialRepository::class);
        $credentialRepository
            ->method('findBy')
            ->with(['user' => $user])
            ->willReturn([$credential]);

        $draftPasswordRepository = $this->createMock(DraftPasswordRepository::class);
        $draftPasswordRepository
            ->method('findBy')
            ->with(['user' => $user])
            ->willReturn([$draftPassword]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects($this->once())
            ->method('flush');

        $service = new CredentialEncryptionRotationService(
            $entityManager,
            $credentialRepository,
            $draftPasswordRepository,
            $encryptionService
        );

        $oldCredentialCiphertext = $credential->getPassword();
        $oldDraftCiphertext = $draftPassword->getPassword();

        $result = $service->rotateUser($user);

        self::assertSame(1, $result['credentials']);
        self::assertSame(1, $result['draftPasswords']);
        self::assertNotSame(str_repeat('a', 64), $user->getCredentialEncryptionKey());
        self::assertNotSame($oldCredentialCiphertext, $credential->getPassword());
        self::assertNotSame($oldDraftCiphertext, $draftPassword->getPassword());

        $encryptionService->setKeyFromUserSecret((string) $user->getCredentialEncryptionKey());
        self::assertSame('top-secret', $encryptionService->decrypt((string) $credential->getPassword()));
        self::assertSame('draft-secret', $encryptionService->decrypt((string) $draftPassword->getPassword()));
    }

    public function testRotateUserDryRunDoesNotPersistOrRewriteSecrets(): void
    {
        $user = (new User())
            ->setEmail('owner@example.com')
            ->setPassword('hashed')
            ->setCompany('Acme')
            ->setCredentialEncryptionKey(str_repeat('b', 64));

        $credential = (new Credential())
            ->setName('Github')
            ->setDomain('github.com')
            ->setUsername('owner')
            ->setUser($user);

        $encryptionService = new EncryptionService();
        $encryptionService->setKeyFromUserSecret((string) $user->getCredentialEncryptionKey());
        $credential->setPassword($encryptionService->encrypt('top-secret'));

        $credentialRepository = $this->createMock(CredentialRepository::class);
        $credentialRepository
            ->method('findBy')
            ->with(['user' => $user])
            ->willReturn([$credential]);

        $draftPasswordRepository = $this->createMock(DraftPasswordRepository::class);
        $draftPasswordRepository
            ->method('findBy')
            ->with(['user' => $user])
            ->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects($this->never())
            ->method('flush');

        $service = new CredentialEncryptionRotationService(
            $entityManager,
            $credentialRepository,
            $draftPasswordRepository,
            $encryptionService
        );

        $originalKey = $user->getCredentialEncryptionKey();
        $originalCiphertext = $credential->getPassword();

        $result = $service->rotateUser($user, true);

        self::assertSame(1, $result['credentials']);
        self::assertSame(0, $result['draftPasswords']);
        self::assertSame($originalKey, $user->getCredentialEncryptionKey());
        self::assertSame($originalCiphertext, $credential->getPassword());
    }
}
