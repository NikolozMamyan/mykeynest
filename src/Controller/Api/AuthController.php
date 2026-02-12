<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\Notification;
use Psr\Log\LoggerInterface;
use App\Repository\UserRepository;
use App\Service\MailerService;
use App\Service\NotificationService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthController extends AbstractController
{
  
  #[Route('/api/register', name: 'api_register', methods: ['POST'])]
  public function register(
    Request $request,
    UserRepository $userRepository,
    UserPasswordHasherInterface $passwordHasher,
    NotificationService $notificationService,
    LoggerInterface $logger,
    EntityManagerInterface $em,
    UrlGeneratorInterface $urlGenerator,
    MailerService $mailerService
): JsonResponse {
    $data = json_decode($request->getContent(), true);

    if (!isset($data['email'], $data['password'])) {
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

    $token = bin2hex(random_bytes(32));
    $expiresAt = (new \DateTime())->modify('+1 hour');
    $user->setApiToken($token);
    $user->setTokenExpiresAt($expiresAt);
    $user->regenerateApiExtensionToken();

    $em->persist($user);
    $em->flush();

    // ✅ Notification in-app
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
            'error' => $e->getMessage()
        ]);
    }

    // ✅ Emails (bienvenue + notif admin)
    try {
        $settingsUrl = $urlGenerator->generate(
    'app_user_profile',
    [],
    UrlGeneratorInterface::ABSOLUTE_URL
);
        // 1) Bienvenue user
        $mailerService->send(
            $user->getEmail(),
            'Welcome to MYKEYNEST',
            'emails/welcome.html.twig',
            [
                'user' => $user,
                'settingsUrl' => $settingsUrl,
            ]
        );

        // 2) Notification admin
        $mailerService->send(
            'nikolozmamyan@gmail.com',
            'Nouvelle inscription KeyNest',
            'emails/admin_new_registration.html.twig',
            [
                'user' => $user,
                'ip' => $request->getClientIp(),
                'userAgent' => $request->headers->get('User-Agent'),
                'registeredAt' => new \DateTime(),
            ]
        );
    } catch (\Exception $e) {
        $logger->error('Failed to send registration emails', [
            'userId' => $user->getId(),
            'error' => $e->getMessage()
        ]);
        // (optionnel) ne pas bloquer l'inscription si mail KO
    }

    $response = new JsonResponse([
        'message' => 'Inscription réussie',
        'user' => [
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
        ]
    ], 201);

    $response->headers->setCookie(
        Cookie::create('AUTH_TOKEN')
            ->withValue($token)
            ->withHttpOnly(true)
            ->withSecure(true)
            ->withPath('/')
            ->withExpires($expiresAt->getTimestamp())
    );

    return $response;
}


    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function login(
        Request $request,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['email'], $data['password'])) {
            return new JsonResponse(['error' => 'Email and password are required'], 400);
        }

        $user = $userRepository->findOneBy(['email' => $data['email']]);

        if (!$user || !$passwordHasher->isPasswordValid($user, $data['password'])) {
            return new JsonResponse(['error' => 'Invalid credentials'], 401);
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTime())->modify('+1 hour');
        $user->setApiToken($token);
        $user->setTokenExpiresAt($expiresAt);
        $em->flush();

        $response = new JsonResponse([
            'message' => 'Connexion réussie',
            'user' => [
                'email' => $user->getEmail(),
                'roles' => $user->getRoles(),
            ]
        ]);

        $response->headers->setCookie(
            Cookie::create('AUTH_TOKEN')
                ->withValue($token)
                ->withHttpOnly(true)
                ->withSecure(true)
                ->withPath('/')
                ->withExpires($expiresAt->getTimestamp())
        );

        return $response;
    }

    #[Route('/api/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(Request $request, EntityManagerInterface $em, UserRepository $userRepository): JsonResponse
    {
        $token = $request->cookies->get('AUTH_TOKEN');
    
        if (!$token) {
            return new JsonResponse(['error' => 'Token manquant'], 401);
        }
    
        $user = $userRepository->findOneBy(['apiToken' => $token]);
    
        if (!$user) {
            return new JsonResponse(['error' => 'Token invalide'], 401);
        }
    
        $user->setApiToken(null);
        $user->setTokenExpiresAt(null);
        $em->flush();
    
        $response = new JsonResponse(['message' => 'Déconnexion réussie']);
        $response->headers->clearCookie('AUTH_TOKEN');
    
        return $response;
    }
    
    
    
    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'Non authentifié'], 401);
        }

        return new JsonResponse([
            'id' => $user->getId(),  
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
        ]);
    }
}
