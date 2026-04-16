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

    private function configureEncryptionForWrite(User $user): void
    {
        $this->encryptionService->setKeyFromUserSecret($user->ensureCredentialEncryptionKey());
    }

    private function decryptWithUserKeys(User $user, string $encrypted): string
    {
        $primaryKey = $user->getCredentialEncryptionKey();
        if (is_string($primaryKey) && $primaryKey !== '') {
            $this->encryptionService->setKeyFromUserSecret($primaryKey);
            $plaintext = $this->encryptionService->decrypt($encrypted);
            if ($plaintext !== '') {
                return $plaintext;
            }
        }

        $legacyKey = $user->getApiExtensionToken();
        if (is_string($legacyKey) && $legacyKey !== '' && $legacyKey !== $primaryKey) {
            $this->encryptionService->setKeyFromUserSecret($legacyKey);

            return $this->encryptionService->decrypt($encrypted);
        }

        return '';
    }

    public function create(string $password, ?string $name, User $user): DraftPassword
    {
        // Crée une copie du service avec la clé utilisateur
        $this->configureEncryptionForWrite($user);

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
        return $this->decryptWithUserKeys($user, $draft->getPassword());
    }

    public function delete(DraftPassword $draft): void
    {
        $this->em->remove($draft);
        $this->em->flush();
    }
}
