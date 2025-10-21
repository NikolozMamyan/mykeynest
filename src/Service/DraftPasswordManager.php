<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\DraftPassword;
use Doctrine\ORM\EntityManagerInterface;

final class DraftPasswordManager
{
    public function __construct(
        private EntityManagerInterface $em,
        private EncryptionService $encryptionService
    ) {}

    public function create(string $password, ?string $name, User $user): DraftPassword
    {
        // Crée une copie du service avec la clé utilisateur
        $this->encryptionService->setEncryptionKey($user->getApiExtensionToken() ?? 'default_key_if_no_user');

        $draft = new DraftPassword();
        $draft->setUser($user);
        $draft->setName($name);
        $draft->setPassword($this->encryptionService->encrypt($password));

        $this->em->persist($draft);
        $this->em->flush();

        return $draft;
    }

    public function decryptPassword(DraftPassword $draft): string
    {
        $user = $draft->getUser();
        $this->encryptionService->setEncryptionKey($user->getApiExtensionToken() ?? 'default_key_if_no_user');

        return $this->encryptionService->decrypt($draft->getPassword());
    }

    public function delete(DraftPassword $draft): void
    {
        $this->em->remove($draft);
        $this->em->flush();
    }
}
