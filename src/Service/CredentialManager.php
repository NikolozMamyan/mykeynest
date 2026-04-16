<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\Credential;
use Doctrine\ORM\EntityManagerInterface;

final class CredentialManager
{
    public function __construct(
        private EncryptionService $encryptionService,
        private EntityManagerInterface $entityManager
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

    /**
     * Ajoute un nouvel identifiant pour un utilisateur.
     */
public function create(Credential $credential, User $user): void
{
    $credential->setUser($user);
    $this->normalizeDomain($credential);

    // ✅ clé correcte pour CE user
    $this->configureEncryptionForWrite($user);

    $plainPassword = (string) $credential->getPassword();
    $credential->setPassword($this->encryptionService->encrypt($plainPassword));

    $this->entityManager->persist($credential);
    $this->entityManager->flush();
}


    /**
     * Met à jour un identifiant existant.
     */
public function update(Credential $credential, string $decryptedPassword, string $originalEncryptedPassword): void
{
    $this->normalizeDomain($credential);

    $owner = $credential->getUser();
    if (!$owner) {
        throw new \RuntimeException('Owner missing, cannot encrypt/decrypt safely.');
    }

    // ✅ clé correcte (owner)
    $this->configureEncryptionForWrite($owner);

    $plainPassword = (string) $credential->getPassword();

    if ($plainPassword !== $decryptedPassword) {
        $credential->setPassword($this->encryptionService->encrypt($plainPassword));
    } else {
        $credential->setPassword($originalEncryptedPassword);
    }

    $credential->setUpdatedAt(new \DateTimeImmutable());
    $this->entityManager->flush();
}


    /**
     * Supprime un identifiant.
     */
    public function delete(Credential $credential): void
    
    {
            foreach ($credential->getSharedAccesses() as $share) {
        $this->entityManager->remove($share);
    }
        $this->entityManager->remove($credential);
        $this->entityManager->flush();
    }

    /**
     * Déchiffre un mot de passe pour affichage.
     */
public function decryptPassword(Credential $credential): string
{
    $owner = $credential->getUser();
    if (!$owner) {
        return '';
    }

    return $this->decryptWithUserKeys($owner, (string) $credential->getPassword());
}


    /**
     * Normalise le domaine pour cohérence (sans http/https/www/fin slash).
     */
    private function normalizeDomain(Credential $credential): void
    {
        $domain = $credential->getDomain();
        $domain = preg_replace(['#^https?://#', '#^www\.#'], '', $domain);
        $domain = rtrim($domain, '/');
        $credential->setDomain($domain);
    }
}
