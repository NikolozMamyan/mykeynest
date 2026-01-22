<?php

namespace App\Security;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

class ApiTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $em
    ) {}

    public function supports(Request $request): ?bool
    {
        $path = $request->getPathInfo();

        // API publiques
        if (in_array($path, ['/api/register', '/api/login', '/api/logout', '/stripe/webhook'], true)) {
            return false;
        }

        // Pages publiques
        if (in_array($path, ['/login', '/register'], true)) {
            return false;
        }

        // Assets (à adapter selon ton projet)
        if (str_starts_with($path, '/assets')) {
            return false;
        }

        // Protéger /api/* et /app* (IMPORTANT: /app sans slash aussi)
        return str_starts_with($path, '/api/') || str_starts_with($path, '/app');
    }

    public function authenticate(Request $request): Passport
    {
        $token = null;

        $authHeader = $request->headers->get('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
        }

        if (!$token) {
            $token = $request->cookies->get('AUTH_TOKEN');
        }

        if (!$token) {
            throw new CustomUserMessageAuthenticationException('No token provided');
        }

        $userRepository = $this->userRepository;
        $em = $this->em;

        return new SelfValidatingPassport(
            new UserBadge($token, function (string $token) use ($userRepository, $em) {
                $user = $userRepository->findOneBy(['apiToken' => $token]);

                if (!$user) {
                    throw new CustomUserMessageAuthenticationException('Invalid API Token');
                }

                $expiresAt = $user->getTokenExpiresAt();
                if ($expiresAt && $expiresAt < new \DateTimeImmutable()) {
                    throw new CustomUserMessageAuthenticationException('Token expired');
                }

                // 🔄 refresh
                $user->setTokenExpiresAt((new \DateTimeImmutable())->modify('+1 hour'));
                $em->persist($user);
                $em->flush();

                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $path = $request->getPathInfo();

        // API => JSON
        if (str_starts_with($path, '/api/')) {
            return new JsonResponse(['error' => $exception->getMessage()], 401);
        }

        // HTML => redirect login + next
        $next = $request->getRequestUri();

        $response = new RedirectResponse('/login?next=' . rawurlencode($next));

        // Optionnel mais utile pour éviter boucle si cookie invalide
        $response->headers->clearCookie('AUTH_TOKEN', '/');

        return $response;
    }
}
