<?php
// src/Service/EncryptionService.php
namespace App\Service;

use Symfony\Bundle\SecurityBundle\Security;
use App\Entity\User;

class EncryptionService
{
    private string $encryptionKey;
    private string $method = 'aes-256-gcm';

    public function __construct(
        private Security $security, // Injection du service de sécurité
    ) {
        // Récupérer l'utilisateur connecté
        /** @var User|null $user */
        $user = $this->security->getUser();

        // Définir la clé à partir du token de l'utilisateur, ou une clé par défaut
        $this->encryptionKey = $user ? $user->getApiExtensionToken() : 'default_key_if_no_user';
    }

    public function encrypt(string $plaintext): string
    {
        if (empty($plaintext)) {
            return '';
        }

        $ivlen = openssl_cipher_iv_length($this->method);
        $iv = random_bytes($ivlen);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            $this->method,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $encrypted): string
    {
        if (empty($encrypted)) {
            return '';
        }
        $decoded = base64_decode($encrypted);
        $ivlen = openssl_cipher_iv_length($this->method);
        $iv = substr($decoded, 0, $ivlen);
        $tag = substr($decoded, $ivlen, 16);
        $ciphertext = substr($decoded, $ivlen + 16);

        $plaintext = openssl_decrypt(
            $ciphertext,
            $this->method,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $plaintext !== false ? $plaintext : '';
    }
    public function setEncryptionKey(string $key): void
{
    $this->encryptionKey = $key;
}

}
