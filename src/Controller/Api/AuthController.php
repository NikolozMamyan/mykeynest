<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\Notification;
use App\Repository\UserRepository;
use App\Service\DeviceIdentifier;
use App\Service\MailerService;
use App\Service\SessionManager;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AuthController extends AbstractController
{
    private function buildAuthCookie(Request $request, string $plainToken, \DateTimeInterface $expiresAt): Cookie
    {
        return Cookie::create('AUTH_TOKEN')
            ->withValue($plainToken)
            ->withHttpOnly(true)
            ->withSecure($request->isSecure())
            ->withSameSite('lax')
            ->withPath('/')
            ->withExpires($expiresAt);
    }

    private function buildDeviceCookie(Request $request, string $deviceId): Cookie
    {
        return Cookie::create(DeviceIdentifier::COOKIE_NAME)
            ->withValue($deviceId)
            ->withHttpOnly(true)
            ->withSecure($request->isSecure())
            ->withSameSite('lax')
            ->withPath('/')
            ->withExpires(new \DateTimeImmutable('+5 years'));
    }

    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(
        Request $request,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher,
        NotificationService $notificationService,
        LoggerInterface $logger,
        EntityManagerInterface $em,
        UrlGeneratorInterface $urlGenerator,
        MailerService $mailerService,
        SessionManager $sessionManager
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data) || !isset($data['email'], $data['password'])) {
            return new JsonResponse(['error' => 'Email and password are required'], 400);
        }

        if ($userRepository->findOneBy(['email' => $data['email']])) {
            return new JsonResponse(['error' => 'Email already in use'], 409);
        }

        $user = new User();
        $user->setCompany($data['company'] ?? null);
        $user->setEmail($data['email']);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($passwordHasher->hashPassword($user, $data['password']));

        $em->persist($user);
        $em->flush();

        // Crée la session + deviceId stable
        [$session, $plainToken, $deviceId] = $sessionManager->createSession($user);

        try {
            $notificationService->createEntityNotification(
                $user,
                'Welcome',
                $user,
                'Complete your profile to fully enjoy the application.',
                Notification::TYPE_SUCCESS,
                '/app/settings/profile',
                'fa-hands-clapping',
                Notification::PRIORITY_LOW
            );
        } catch (\Exception $e) {
            $logger->error('Failed to create registration notification', [
                'userId' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $settingsUrl = $urlGenerator->generate(
                'app_user_profile',
                [],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            $mailerService->send(
                $user->getEmail(),
                'Welcome to MYKEYNEST',
                'emails/welcome.html.twig',
                [
                    'user' => $user,
                    'settingsUrl' => $settingsUrl,
                ]
            );

            $mailerService->send(
                'nikolozmamyan@gmail.com',
                'Nouvelle inscription KeyNest',
                'emails/admin_new_registration.html.twig',
                [
                    'user' => $user,
                    'ip' => $request->getClientIp(),
                    'userAgent' => $request->headers->get('User-Agent'),
                    'registeredAt' => new \DateTimeImmutable(),
                ]
            );
        } catch (\Exception $e) {
            $logger->error('Failed to send registration emails', [
                'userId' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        $response = new JsonResponse([
            'message' => 'Inscription réussie',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'roles' => $user->getRoles(),
            ],
            'session' => [
                'id' => $session->getId(),
                'expiresAt' => $session->getExpiresAt()->format(DATE_ATOM),
            ],
        ], 201);

        $response->headers->setCookie(
            $this->buildAuthCookie($request, $plainToken, $session->getExpiresAt())
        );

        $response->headers->setCookie(
            $this->buildDeviceCookie($request, $deviceId)
        );

        return $response;
    }

    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function login(
        Request $request,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher,
        SessionManager $sessionManager
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data) || !isset($data['email'], $data['password'])) {
            return new JsonResponse(['error' => 'Email and password are required'], 400);
        }

        $user = $userRepository->findOneBy(['email' => $data['email']]);

        if (!$user || !$passwordHasher->isPasswordValid($user, $data['password'])) {
            return new JsonResponse(['error' => 'Invalid credentials'], 401);
        }

        try {
            [$session, $plainToken, $deviceId] = $sessionManager->createSession($user);
        } catch (\RuntimeException $e) {
            return new JsonResponse([
                'error' => 'Connexion bloquée',
                'message' => $e->getMessage(),
            ], 403);
        }

        $response = new JsonResponse([
            'message' => 'Connexion réussie',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'roles' => $user->getRoles(),
            ],
            'session' => [
                'id' => $session->getId(),
                'expiresAt' => $session->getExpiresAt()->format(DATE_ATOM),
            ],
        ]);

        $response->headers->setCookie(
            $this->buildAuthCookie($request, $plainToken, $session->getExpiresAt())
        );

        $response->headers->setCookie(
            $this->buildDeviceCookie($request, $deviceId)
        );

        return $response;
    }

    #[Route('/api/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(
        Request $request,
        SessionManager $sessionManager
    ): JsonResponse {
        $plainToken = $request->cookies->get('AUTH_TOKEN');

        if (!$plainToken) {
            return new JsonResponse(['error' => 'Token manquant'], 401);
        }

        $session = $sessionManager->findActiveSessionByPlainToken($plainToken);

        if (!$session) {
            $response = new JsonResponse(['error' => 'Session invalide'], 401);
            $response->headers->clearCookie('AUTH_TOKEN', '/');

            return $response;
        }

        $sessionManager->revoke($session, 'logout');

        $response = new JsonResponse(['message' => 'Déconnexion réussie']);
        $response->headers->clearCookie('AUTH_TOKEN', '/');

        return $response;
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié'], 401);
        }

        return new JsonResponse([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
        ]);
    }
}