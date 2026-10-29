<?php

declare(strict_types=1);

namespace App\Service;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GoogleOAuthService
{
    private const SESSION_KEY = '_google_oauth';

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        #[Autowire('%env(GOOGLE_CLIENT_ID)%')] private string $clientId,
        #[Autowire('%env(GOOGLE_CLIENT_SECRET)%')] private string $clientSecret,
        #[Autowire('%env(GOOGLE_REDIRECT_URI)%')] private string $redirectUri,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->clientId && '' !== $this->clientSecret && '' !== $this->redirectUri;
    }

    public function begin(Request $request, array $context): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('auth.google.unavailable');
        }
        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));
        $request->getSession()->migrate(true);
        $request->getSession()->set(self::SESSION_KEY, [
            'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'expires' => time() + 600,
            'locale' => $request->getLocale(), 'context' => $context,
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $this->clientId, 'redirect_uri' => $this->redirectUri,
            'response_type' => 'code', 'scope' => 'openid email profile', 'state' => $state,
            'nonce' => $nonce, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256', 'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function finish(Request $request): array
    {
        $transaction = $request->getSession()->remove(self::SESSION_KEY);
        $query = $request->query->all();
        if (!$this->isConfigured() || !is_array($transaction) || ($transaction['expires'] ?? 0) < time()
            || !is_string($query['state'] ?? null) || !hash_equals($transaction['state'], $query['state'])) {
            throw new \RuntimeException('auth.google.invalid_state');
        }
        $request->setLocale(in_array($transaction['locale'], ['fr', 'en'], true) ? $transaction['locale'] : 'en');
        if (isset($query['error'])) {
            throw new \RuntimeException('auth.google.cancelled');
        }
        if (!is_string($query['code'] ?? null) || '' === $query['code']) {
            throw new \RuntimeException('auth.google.failed');
        }
        $tokens = $this->httpClient->request('POST', 'https://oauth2.googleapis.com/token', [
            'body' => ['grant_type' => 'authorization_code', 'code' => $query['code'], 'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret, 'redirect_uri' => $this->redirectUri, 'code_verifier' => $transaction['verifier']],
            'timeout' => 8, 'max_duration' => 12, 'max_redirects' => 0,
        ])->toArray();
        if (!is_string($tokens['id_token'] ?? null)) {
            throw new \RuntimeException('auth.google.failed');
        }
        $fetchKeys = function (ItemInterface $item): array {
            $item->expiresAfter(3600);

            return $this->httpClient->request('GET', 'https://www.googleapis.com/oauth2/v3/certs', [
                'timeout' => 8, 'max_duration' => 12, 'max_redirects' => 0,
            ])->toArray();
        };
        $keys = $this->cache->get('google_oidc_signing_keys', $fetchKeys);
        $header = JWT::jsonDecode(JWT::urlsafeB64Decode(explode('.', $tokens['id_token'])[0]));
        if (is_string($header->kid ?? null) && !in_array($header->kid, array_column($keys['keys'] ?? [], 'kid'), true)) {
            $this->cache->delete('google_oidc_signing_keys');
            $keys = $this->cache->get('google_oidc_signing_keys', $fetchKeys);
        }
        $keys['keys'] = array_values(array_filter($keys['keys'] ?? [], static fn (array $key): bool => ($key['alg'] ?? '') === 'RS256'));
        $claims = (array) JWT::decode($tokens['id_token'], JWK::parseKeySet($keys, 'RS256'));
        if (!in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            || ($claims['aud'] ?? null) !== $this->clientId || (isset($claims['azp']) && $claims['azp'] !== $this->clientId)
            || !is_numeric($claims['exp'] ?? null) || $claims['exp'] <= time()
            || !is_numeric($claims['iat'] ?? null) || $claims['iat'] > time()
            || !is_string($claims['nonce'] ?? null) || !hash_equals($transaction['nonce'], $claims['nonce'])
            || !is_string($claims['sub'] ?? null) || '' === $claims['sub'] || strlen($claims['sub']) > 255
            || !in_array($claims['email_verified'] ?? false, [true, 'true'], true)
            || !is_string($claims['email'] ?? null) || mb_strlen($claims['email']) > 180
            || false === filter_var($claims['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('auth.google.failed');
        }

        return ['identity' => $claims, 'context' => $transaction['context']];
    }
}
