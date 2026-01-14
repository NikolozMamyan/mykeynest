<?php

namespace App\Service;

class EncryptionService
{
    private string $key; // 32 bytes
    private string $method = 'aes-256-gcm';

    public function __construct()
    {
        // ✅ clé par défaut serveur (au cas où)
        $this->key = $this->deriveKey($_ENV['APP_SECRET'] ?? 'fallback_secret');
    }

    /**
     * ✅ Dérive une clé 32 bytes depuis un secret (token user + APP_SECRET).
     */
    public function setKeyFromUserToken(string $userToken): void
    {
        $appSecret = $_ENV['APP_SECRET'] ?? '';
        $this->key = $this->deriveKey($appSecret . '|' . $userToken);
    }

    /**
     * Si tu veux garder setEncryptionKey, on le force à 32 bytes.
     */
    public function setEncryptionKey(string $key): void
    {
        $this->key = $this->deriveKey($key);
    }

    private function deriveKey(string $material): string
    {
        // ✅ 32 bytes
        return hash('sha256', $material, true);
    }

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $ivlen = openssl_cipher_iv_length($this->method);
        if ($ivlen === false || $ivlen <= 0) {
            throw new \RuntimeException('Invalid cipher iv length');
        }

        $iv = random_bytes($ivlen);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            $this->method,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false || $tag === '') {
            throw new \RuntimeException('Encryption failed');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }

        $decoded = base64_decode($encrypted, true); // ✅ strict
        if ($decoded === false) {
            return '';
        }

        $ivlen = openssl_cipher_iv_length($this->method);
        if ($ivlen === false || $ivlen <= 0) {
            return '';
        }

        // iv + tag(16) + ciphertext(min 1)
        if (strlen($decoded) < ($ivlen + 16 + 1)) {
            return '';
        }

        $iv = substr($decoded, 0, $ivlen);
        $tag = substr($decoded, $ivlen, 16);
        $ciphertext = substr($decoded, $ivlen + 16);

        $plaintext = openssl_decrypt(
            $ciphertext,
            $this->method,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $plaintext !== false ? $plaintext : '';
    }
}
