<?php

namespace App\Service;

use App\Entity\Credential;
use App\Entity\DraftPassword;
use App\Entity\User;
use App\Repository\CredentialRepository;
use App\Repository\DraftPasswordRepository;
use Doctrine\ORM\EntityManagerInterface;

final class CredentialEncryptionRotationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CredentialRepository $credentialRepository,
        private DraftPasswordRepository $draftPasswordRepository,
        private EncryptionService $encryptionService
    ) {}

    /**
     * @return array{credentials:int,draftPasswords:int,newKey:string}
     */
    public function rotateUser(User $user, bool $dryRun = false): array
    {
        /** @var Credential[] $credentials */
        $credentials = $this->credentialRepository->findBy(['user' => $user]);
        /** @var DraftPassword[] $draftPasswords */
        $draftPasswords = $this->draftPasswordRepository->findBy(['user' => $user]);

        $decryptedCredentials = [];
        foreach ($credentials as $credential) {
            $encryptedPassword = (string) $credential->getPassword();
            $decryptedCredentials[] = [$credential, $this->decryptWithUserKeys($user, $encryptedPassword)];
        }

        $decryptedDraftPasswords = [];
        foreach ($draftPasswords as $draftPassword) {
            $encryptedPassword = (string) $draftPassword->getPassword();
            $decryptedDraftPasswords[] = [$draftPassword, $this->decryptWithUserKeys($user, $encryptedPassword)];
        }

        $newKey = bin2hex(random_bytes(32));

        if (!$dryRun) {
            $user->setCredentialEncryptionKey($newKey);
            $this->encryptionService->setKeyFromUserSecret($newKey);

            foreach ($decryptedCredentials as [$credential, $plaintext]) {
                $credential->setPassword($plaintext === '' ? '' : $this->encryptionService->encrypt($plaintext));
            }

            foreach ($decryptedDraftPasswords as [$draftPassword, $plaintext]) {
                $draftPassword->setPassword($plaintext === '' ? '' : $this->encryptionService->encrypt($plaintext));
            }

            $this->entityManager->flush();
        }

        return [
            'credentials' => count($decryptedCredentials),
            'draftPasswords' => count($decryptedDraftPasswords),
            'newKey' => $newKey,
        ];
    }

    private function decryptWithUserKeys(User $user, string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }

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
            $plaintext = $this->encryptionService->decrypt($encrypted);
            if ($plaintext !== '') {
                return $plaintext;
            }
        }

        throw new \RuntimeException(sprintf(
            'Unable to decrypt encrypted secret for user #%d.',
            $user->getId() ?? 0
        ));
    }
}
