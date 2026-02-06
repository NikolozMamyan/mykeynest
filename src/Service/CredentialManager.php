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

    /**
     * Ajoute un nouvel identifiant pour un utilisateur.
     */
public function create(Credential $credential, User $user): void
{
    $credential->setUser($user);
    $this->normalizeDomain($credential);

    // ✅ clé correcte pour CE user
    $this->encryptionService->setKeyFromUserToken($user->getApiExtensionToken());

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
    if (!$owner || !$owner->getApiExtensionToken()) {
        throw new \RuntimeException('Owner missing apiExtensionToken, cannot encrypt/decrypt safely.');
    }

    // ✅ clé correcte (owner)
    $this->encryptionService->setKeyFromUserToken($owner->getApiExtensionToken());

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
    if (!$owner || !$owner->getApiExtensionToken()) {
        return '';
    }

    $this->encryptionService->setKeyFromUserToken($owner->getApiExtensionToken());

    return $this->encryptionService->decrypt((string) $credential->getPassword());
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
