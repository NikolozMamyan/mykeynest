<?php

namespace App\Controller\Front;

use App\Repository\UserRepository;
use App\Service\DeviceIdentifier;
use App\Service\SessionManager;
use App\Service\TokenCleaner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class AuthPageController extends AbstractController
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

    #[Route('/login', name: 'show_login', methods: ['GET'])]
    public function login(Request $request, TokenCleaner $cleaner, Security $security): Response
    {
        $response = new Response();

        $cleaner->clearTokenFromRequest($request, $response);

        $user = $this->getUser();
        if ($user) {
            $security->logout(false);
        }

        $response->setContent(
            $this->renderView('auth/login.html.twig')
        );

        return $response;
    }

    #[Route('/register', name: 'show_register', methods: ['GET'])]
    public function showRegister(Request $request, TokenCleaner $cleaner, Security $security): Response
    {
        $response = new Response();

        $cleaner->clearTokenFromRequest($request, $response);

        $user = $this->getUser();
        if ($user) {
            $security->logout(false);
        }

        $response->setContent(
            $this->renderView('auth/register.html.twig')
        );

        return $response;
    }

    #[Route('/', name: 'app_landing', methods: ['GET'])]
    public function landing(Request $request, TokenCleaner $cleaner, Security $security): Response
    {
        $response = new Response();

        $cleaner->clearTokenFromRequest($request, $response);

        $user = $this->getUser();
        if ($user) {
            $security->logout(false);
        }

        $response->setContent(
            $this->renderView('auth/landing.html.twig')
        );

        return $response;
    }

    #[Route('/guest/register', name: 'app_guest_register', methods: ['GET', 'POST'])]
    public function guestRegister(
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        SessionManager $sessionManager
    ): Response {
        $token = $request->query->get('token');
        $email = $request->query->get('email');

        if (!$token) {
            throw $this->createNotFoundException('Token manquant');
        }

        $user = $userRepository->findOneBy(['apiToken' => $token]);

        if (!$user) {
            throw $this->createNotFoundException('Token invalide');
        }

        if (!in_array('ROLE_GUEST', $user->getRoles(), true)) {
            $this->addFlash('error', 'Invitation deja utilisee.');

            return $this->redirectToRoute('show_login');
        }

        if ($user->getTokenExpiresAt() && $user->getTokenExpiresAt() < new \DateTimeImmutable()) {
            $this->addFlash('error', 'Lien expire. Demandez une nouvelle invitation.');

            return $this->redirectToRoute('show_login');
        }

        if ($email && strtolower($user->getEmail()) !== strtolower($email)) {
            throw $this->createAccessDeniedException('Email mismatch');
        }

        if ($request->isMethod('POST')) {
            $plainPassword = (string) $request->request->get('password');
            $company = (string) $request->request->get('company');

            $user->setPassword($hasher->hashPassword($user, $plainPassword));
            $user->setCompany($company);
            $user->setApiToken(null);
            $user->setTokenExpiresAt(null);
            $user->setRoles(['ROLE_USER']);

            $em->flush();

            try {
                [$session, $plainToken, $deviceId] = $sessionManager->createSession($user);
            } catch (\RuntimeException $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->redirectToRoute('show_login');
            }

            $response = $this->redirectToRoute('app_credential');
            $response->headers->setCookie($this->buildAuthCookie($request, $plainToken, $session->getExpiresAt()));
            $response->headers->setCookie($this->buildDeviceCookie($request, $deviceId));

            return $response;
        }

        return $this->render('guest/register.html.twig', [
            'user' => $user,
            'token' => $token,
        ]);
    }

    #[Route('/app/security/pending-login', name: 'app_pending_login', methods: ['GET'])]
    public function pendingLoginPage(): Response
    {
        return $this->render('security/pending_login.html.twig');
    }
}
