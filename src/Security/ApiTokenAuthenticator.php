<?php

namespace App\Security;

use App\Service\SessionManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class ApiTokenAuthenticator extends AbstractAuthenticator
{
    private const PUBLIC_EXACT_PATHS = [
        '/api/register',
        '/api/login',
        '/api/logout',
        '/api/forgot-password',
        '/api/reset-password',
        '/api/reset-password/verify',
        '/api/login-challenge/status',
        '/api/login-challenge/complete',
        '/stripe/webhook',

        '/',
        '/login',
        '/register',
        '/forgot-password',
        '/reset-password',
        '/app/security/pending-login',
    ];

    private const PUBLIC_PREFIXES = [
        '/reset-password/',
        '/verify-email/',
        '/public/',
        '/api/login-challenge/',
    ];

    private const ASSET_PREFIXES = [
        '/assets',
        '/build',
        '/bundles',
        '/images',
        '/img',
        '/css',
        '/js',
        '/fonts',
        '/uploads',
        '/media',
    ];

    private const PUBLIC_FILES = [
        '/favicon.ico',
        '/robots.txt',
        '/sitemap.xml',
    ];

    public function __construct(
        private SessionManager $sessionManager
    ) {
    }

    public function supports(Request $request): ?bool
    {
        $path = $this->normalizePath($request->getPathInfo());

        if ($this->isPublicPath($path)) {
            return false;
        }

        if ($this->isAssetPath($path)) {
            return false;
        }

        if ($this->isDevToolPath($path)) {
            return false;
        }

        return $this->isProtectedPath($path);
    }

    public function authenticate(Request $request): Passport
    {
        $plainToken = $this->extractToken($request);

        if (!$plainToken) {
            throw new CustomUserMessageAuthenticationException('No token provided');
        }

        $session = $this->sessionManager->findActiveSessionByPlainToken($plainToken);

        if (!$session) {
            throw new CustomUserMessageAuthenticationException('Session invalide, expirée ou révoquée');
        }

        $user = $session->getUser();

        if (!$user) {
            throw new CustomUserMessageAuthenticationException('Utilisateur introuvable');
        }

        $this->sessionManager->touch($session);

        return new SelfValidatingPassport(
            new UserBadge(
                $user->getUserIdentifier(),
                fn () => $user
            )
        );
    }

    public function onAuthenticationSuccess(
        Request $request,
        TokenInterface $token,
        string $firewallName
    ): ?Response {
        return null;
    }

    public function onAuthenticationFailure(
        Request $request,
        AuthenticationException $exception
    ): ?Response {
        $path = $this->normalizePath($request->getPathInfo());

        if (str_starts_with($path, '/api/')) {
            $response = new JsonResponse([
                'error' => $exception->getMessage(),
            ], Response::HTTP_UNAUTHORIZED);

            $response->headers->clearCookie('AUTH_TOKEN', '/');

            return $response;
        }

        $response = new RedirectResponse('/login?next=' . rawurlencode($request->getRequestUri()));
        $response->headers->clearCookie('AUTH_TOKEN', '/');

        return $response;
    }

    private function extractToken(Request $request): ?string
    {
        $authHeader = $request->headers->get('Authorization');

        if (is_string($authHeader) && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $token = trim($matches[1]);

            if ($token !== '') {
                return $token;
            }
        }

        $cookieToken = $request->cookies->get('AUTH_TOKEN');

        if (is_string($cookieToken)) {
            $cookieToken = trim($cookieToken);

            if ($cookieToken !== '') {
                return $cookieToken;
            }
        }

        return null;
    }

    private function normalizePath(string $path): string
    {
        $normalized = rtrim($path, '/');

        return $normalized === '' ? '/' : $normalized;
    }

    private function isPublicPath(string $path): bool
    {
        if (in_array($path, self::PUBLIC_EXACT_PATHS, true)) {
            return true;
        }

        if (in_array($path, self::PUBLIC_FILES, true)) {
            return true;
        }

        foreach (self::PUBLIC_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isAssetPath(string $path): bool
    {
        foreach (self::ASSET_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isDevToolPath(string $path): bool
    {
        return str_starts_with($path, '/_wdt')
            || str_starts_with($path, '/_profiler');
    }

    private function isProtectedPath(string $path): bool
    {
        return $path === '/app'
            || str_starts_with($path, '/app/')
            || str_starts_with($path, '/api/');
    }
}