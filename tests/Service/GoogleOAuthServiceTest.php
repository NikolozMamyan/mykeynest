<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GoogleOAuthService;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class GoogleOAuthServiceTest extends TestCase
{
    private static string $privateKey = '';
    private static array $jwk;

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, self::$privateKey);
        $details = openssl_pkey_get_details($key);
        self::$jwk = ['kty' => 'RSA', 'alg' => 'RS256', 'kid' => 'test-key', 'use' => 'sig',
            'n' => JWT::urlsafeB64Encode($details['rsa']['n']), 'e' => JWT::urlsafeB64Encode($details['rsa']['e'])];
    }

    public function testValidatesSignedIdentityAndPreservesProfileAndPkce(): void
    {
        [$service, $request, $query, $client] = $this->flow();
        self::assertSame('openid email profile', $query['scope']);
        self::assertSame('S256', $query['code_challenge_method']);
        $request->query->add(['code' => 'authorization-code', 'state' => $query['state']]);
        $result = $service->finish($request);
        self::assertSame('google-subject', $result['identity']['sub']);
        self::assertSame('Company', $result['context']['company']);
        self::assertSame(2, $client->getRequestsCount());
        self::assertSame('fr', $request->getLocale());
        self::assertFalse($request->getSession()->has('_google_oauth'));
        self::expectException(\RuntimeException::class);
        $service->finish($request);
    }

    /** @dataProvider invalidIdentities */
    public function testRejectsInvalidIdentityClaims(array $overrides): void
    {
        [$service, $request, $query] = $this->flow($overrides);
        $request->query->add(['code' => 'code', 'state' => $query['state']]);
        self::expectException(\RuntimeException::class);
        $service->finish($request);
    }

    public static function invalidIdentities(): array
    {
        return [
            [['aud' => 'another-client']], [['iss' => 'https://attacker.example']],
            [['nonce' => 'wrong-nonce']], [['email_verified' => false]], [['email' => 'invalid']],
            [['sub' => '']], [['azp' => 'another-client']],
        ];
    }

    public function testRejectsExpiredSignedToken(): void
    {
        [$service, $request, $query] = $this->flow(['exp' => time() - 30]);
        $request->query->add(['code' => 'code', 'state' => $query['state']]);
        self::expectException(\UnexpectedValueException::class);
        $service->finish($request);
    }

    public function testRejectsForgedSignature(): void
    {
        [$service, $request, $query] = $this->flow([], true);
        $request->query->add(['code' => 'code', 'state' => $query['state']]);
        self::expectException(\UnexpectedValueException::class);
        $service->finish($request);
    }

    public function testRejectsInvalidStateBeforeContactingGoogle(): void
    {
        [$service, $request, , $client] = $this->flow();
        $request->query->add(['code' => 'code', 'state' => 'forged-state']);
        try {
            $service->finish($request);
            self::fail('Invalid state was accepted.');
        } catch (\RuntimeException) {
            self::assertSame(0, $client->getRequestsCount());
            self::assertFalse($request->getSession()->has('_google_oauth'));
        }
    }

    public function testRefreshesCachedKeysWhenGoogleRotatesItsSigningKey(): void
    {
        $cache = new ArrayAdapter();
        $cache->get('google_oidc_signing_keys', static fn () => ['keys' => [array_replace(self::$jwk, ['kid' => 'old-key'])]]);
        [$service, $request, $query, $client] = $this->flow(cache: $cache);
        $request->query->add(['code' => 'code', 'state' => $query['state']]);
        self::assertSame('google-subject', $service->finish($request)['identity']['sub']);
        self::assertSame(2, $client->getRequestsCount());
    }

    private function flow(array $overrides = [], bool $forged = false, ?ArrayAdapter $cache = null): array
    {
        $request = Request::create('https://key-nest.com/auth/google/callback');
        $request->setLocale('fr');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $query = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$query, $overrides, $forged): MockResponse {
            if (str_ends_with($url, '/token')) {
                parse_str($options['body'], $body);
                self::assertSame('S256', $query['code_challenge_method']);
                self::assertSame($query['code_challenge'], JWT::urlsafeB64Encode(hash('sha256', $body['code_verifier'], true)));
                self::assertSame('test-secret', $body['client_secret']);
                $claims = array_replace(['iss' => 'https://accounts.google.com', 'aud' => 'test-client', 'sub' => 'google-subject',
                    'email' => 'person@gmail.com', 'email_verified' => true, 'iat' => time() - 5, 'exp' => time() + 3600, 'nonce' => $query['nonce']], $overrides);
                $token = JWT::encode($claims, self::$privateKey, 'RS256', 'test-key');
                if ($forged) {
                    $parts = explode('.', $token);
                    $parts[2] = JWT::urlsafeB64Encode(str_repeat('a', 256));
                    $token = implode('.', $parts);
                }

                return new MockResponse(json_encode(['id_token' => $token]));
            }
            self::assertSame('https://www.googleapis.com/oauth2/v3/certs', $url);

            return new MockResponse(json_encode(['keys' => [self::$jwk]]));
        });
        $service = new GoogleOAuthService($client, $cache ?? new ArrayAdapter(), 'test-client', 'test-secret', 'https://key-nest.com/auth/google/callback');
        parse_str(parse_url($service->begin($request, ['company' => 'Company']), PHP_URL_QUERY), $query);

        return [$service, $request, $query, $client];
    }
}
