<?php

namespace App\Service;

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
    public function create(Credential $credential, object $user): void
    {
        $credential->setUser($user);
        $this->normalizeDomain($credential);

        $encryptedPassword = $this->encryptionService->encrypt($credential->getPassword());
        $credential->setPassword($encryptedPassword);

        $this->entityManager->persist($credential);
        $this->entityManager->flush();
    }

    /**
     * Met à jour un identifiant existant.
     */
    public function update(Credential $credential, string $decryptedPassword, string $originalEncryptedPassword): void
    {
        $this->normalizeDomain($credential);

        $plainPassword = $credential->getPassword();
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
        $this->entityManager->remove($credential);
        $this->entityManager->flush();
    }

    /**
     * Déchiffre un mot de passe pour affichage.
     */
    public function decryptPassword(Credential $credential): string
    {
        return $this->encryptionService->decrypt($credential->getPassword());
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
