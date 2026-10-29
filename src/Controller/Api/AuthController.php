<?php

namespace App\Controller\Api;

use App\Entity\LoginChallenge;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\DeviceIdentifier;
use App\Service\LoginChallengeManager;
use App\Service\UserLoginService;
use App\Service\UserRegistrationService;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Service\SessionManager;
use App\Service\UserDeviceManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController extends AbstractController
{
    public function __construct(
        private RateLimiterFactory $authLoginLimiter,
        private RateLimiterFactory $authRegisterLimiter,
        private UserLoginService $userLogin,
        private UserRegistrationService $registrations,
        private TranslatorInterface $translator,
    ) {
    }

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

    private function buildLimiterKey(Request $request, ?string $identifier = null): string
    {
        $ip = (string) ($request->getClientIp() ?? 'unknown');
        $identifier = $identifier !== null ? trim(mb_strtolower($identifier)) : '';

        return $identifier !== '' ? $ip . '|' . $identifier : $ip;
    }

    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(Request $request, UserRepository $userRepository): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $email = is_array($data) && is_string($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : '';
        $limit = $this->authRegisterLimiter->create($this->buildLimiterKey($request, $email))->consume(1);
        if (!$limit->isAccepted()) {
            return new JsonResponse(['error' => 'Too many registration attempts. Please try again later.', 'retryAfter' => $limit->getRetryAfter()?->format(DATE_ATOM)], 429);
        }
        if (!is_array($data) || !is_string($data['password'] ?? null) || '' === $data['password'] || strlen($data['password']) > 4096
            || mb_strlen($email) > 180 || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => $this->translator->trans('auth.register.errors.invalid_input')], 400);
        }
        try {
            $company = $this->registrations->companyFor($data);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $this->translator->trans($exception->getMessage())], 400);
        }
        if ($userRepository->findOneBy(['email' => $email])) {
            return new JsonResponse(['error' => $this->translator->trans('auth.register.errors.email_used')], 409);
        }
        $user = $this->registrations->register($request, $email, $data['password'], $company);

        return $this->userLogin->complete($user, $request, true);
    }

    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function login(Request $request, UserRepository $userRepository, UserPasswordHasherInterface $passwordHasher): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $email = is_array($data) && isset($data['email']) && is_string($data['email']) ? $data['email'] : null;
        $limit = $this->authLoginLimiter->create($this->buildLimiterKey($request, $email))->consume(1);

        if (!$limit->isAccepted()) {
            $retryAfter = $limit->getRetryAfter();

            return new JsonResponse([
                'error' => 'Too many login attempts. Please try again later.',
                'retryAfter' => $retryAfter?->format(DATE_ATOM),
            ], 429);
        }

        if (!is_array($data) || !is_string($data['email'] ?? null) || !is_string($data['password'] ?? null)) {
            return new JsonResponse(['error' => 'Email and password are required'], 400);
        }

        $user = $userRepository->findOneBy(['email' => $data['email']]);

        if (!$user || !$passwordHasher->isPasswordValid($user, $data['password'])) {
            return new JsonResponse(['error' => 'Invalid credentials'], 401);
        }
        return $this->userLogin->complete($user, $request);
    }

    #[Route('/api/login-challenge/status', name: 'api_login_challenge_status', methods: ['GET'])]
    public function loginChallengeStatus(
        Request $request,
        LoginChallengeManager $loginChallengeManager
    ): JsonResponse {
        $plainToken = $request->cookies->get(LoginChallengeManager::COOKIE_NAME);

        if (!$plainToken) {
            return new JsonResponse([
                'error' => 'Aucune tentative de connexion en attente.',
            ], 404);
        }

        $challenge = $loginChallengeManager->findByPlainToken($plainToken);

        if (!$challenge) {
            return new JsonResponse([
                'error' => 'Tentative introuvable.',
            ], 404);
        }

        $loginChallengeManager->markExpiredIfNeeded($challenge);

        return new JsonResponse([
            'status' => $challenge->getStatus(),
            'expiresAt' => $challenge->getExpiresAt()?->format(DATE_ATOM),
            'email' => $challenge->getUser()?->getEmail(),
        ]);
    }

    #[Route('/api/login-challenge/complete', name: 'api_login_challenge_complete', methods: ['POST'])]
    public function completeLoginChallenge(
        Request $request,
        LoginChallengeManager $loginChallengeManager,
        SessionManager $sessionManager,
        UrlGeneratorInterface $urlGenerator
    ): JsonResponse {
        $plainToken = $request->cookies->get(LoginChallengeManager::COOKIE_NAME);

        if (!$plainToken) {
            return new JsonResponse(['error' => 'Aucune validation en attente.'], 404);
        }

        $challenge = $loginChallengeManager->findValidByPlainToken($plainToken);

        if (!$challenge) {
            $response = new JsonResponse(['error' => 'Tentative invalide ou expirée.'], 400);
            $response->headers->clearCookie(LoginChallengeManager::COOKIE_NAME, '/');

            return $response;
        }

        if ($challenge->isCompleted()) {
            return new JsonResponse([
                'error' => 'Cette tentative a déjà été finalisée.',
            ], 409);
        }

        if (!$challenge->isApproved()) {
            return new JsonResponse([
                'error' => 'Cette tentative n’a pas encore été approuvée.',
                'status' => $challenge->getStatus(),
            ], 409);
        }

        $currentDeviceId = $sessionManager->getCurrentDeviceId();
        $challengeDeviceId = $challenge->getDeviceId();

        if (
            $currentDeviceId === null
            || $challengeDeviceId === null
            || !hash_equals($challengeDeviceId, $currentDeviceId)
        ) {
            $response = new JsonResponse([
                'error' => 'Appareil de validation différent',
                'message' => 'Finalisez la connexion depuis le navigateur qui a demandé le challenge.',
            ], 409);
            $response->headers->clearCookie(LoginChallengeManager::COOKIE_NAME, '/');

            return $response;
        }

        if (!$loginChallengeManager->claimForCompletion($challenge)) {
            return new JsonResponse([
                'error' => 'Cette tentative a déjà été utilisée ou a expiré.',
            ], 409);
        }

        try {
            $sessionManager->trustDevice(
                $challenge->getUser(),
                $challengeDeviceId,
                $challenge->getDeviceName()
            );
            [$session, $plainAuthToken, $deviceId] = $sessionManager->createSession(
                $challenge->getUser(),
                $challenge->getDeviceName(),
                $challengeDeviceId
            );
        } catch (\RuntimeException $e) {
            return new JsonResponse([
                'error' => 'Connexion bloquée',
                'message' => $e->getMessage(),
            ], 403);
        }

        $response = new JsonResponse([
            'message' => 'Connexion validée et finalisée.',
            'user' => [
                'id' => $challenge->getUser()?->getId(),
                'email' => $challenge->getUser()?->getEmail(),
                'roles' => $challenge->getUser()?->getRoles(),
            ],
            'session' => [
                'id' => $session->getId(),
                'expiresAt' => $session->getExpiresAt()->format(DATE_ATOM),
            ],
            'redirectUrl' => $this->userLogin->redirectUrl($challenge->getUser(), $request),
        ]);

        $response->headers->setCookie($this->buildAuthCookie($request, $plainAuthToken, $session->getExpiresAt()));
        $response->headers->setCookie($this->buildDeviceCookie($request, $deviceId));
        $response->headers->clearCookie(LoginChallengeManager::COOKIE_NAME, '/');

        return $response;
    }

    #[Route('/api/login-challenge/{token}/approve', name: 'api_login_challenge_approve', methods: ['GET', 'POST'])]
    public function approveLoginChallenge(
        Request $request,
        string $token,
        LoginChallengeManager $loginChallengeManager
    ): Response {
        $challenge = $loginChallengeManager->findValidByPlainToken($token);

        if (!$challenge) {
            return new Response(
                '<h1>Lien invalide ou expiré</h1><p>Cette demande de connexion n’est plus valide.</p>',
                400
            );
        }

        if ($request->isMethod(Request::METHOD_GET)) {
            return $this->render('security/challenge_action_confirm.html.twig', [
                'title' => 'Confirmer la connexion',
                'message' => 'Confirmez-vous cette tentative de connexion ?',
                'confirmLabel' => 'Oui, approuver la connexion',
                'cancelLabel' => 'Annuler',
                'csrfTokenId' => 'login_challenge_approve_' . $token,
                'autoSubmit' => true,
            ]);
        }

        if (!$this->isCsrfTokenValid('login_challenge_approve_' . $token, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF token invalide.');
        }

        $loginChallengeManager->approve($challenge);

        return $this->redirectToRoute('app_pending_login', [
            'approved' => 1,
        ]);
    }

    #[Route('/api/login-challenge/{token}/reject', name: 'api_login_challenge_reject', methods: ['GET', 'POST'])]
    public function rejectLoginChallenge(
        Request $request,
        string $token,
        LoginChallengeManager $loginChallengeManager,
        SessionManager $sessionManager
    ): Response {
        $challenge = $loginChallengeManager->findValidByPlainToken($token);

        if (!$challenge) {
            return new Response(
                '<h1>Lien invalide ou expiré</h1><p>Cette demande de connexion n’est plus valide.</p>',
                400
            );
        }

        if ($request->isMethod(Request::METHOD_GET)) {
            return $this->render('security/challenge_action_confirm.html.twig', [
                'title' => 'Refuser la connexion',
                'message' => 'Refuser cette tentative bloquera l’appareil concerné. Voulez-vous continuer ?',
                'confirmLabel' => 'Oui, refuser et bloquer',
                'cancelLabel' => 'Annuler',
                'csrfTokenId' => 'login_challenge_reject_' . $token,
                'autoSubmit' => true,
            ]);
        }

        if (!$this->isCsrfTokenValid('login_challenge_reject_' . $token, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF token invalide.');
        }

        $loginChallengeManager->reject($challenge);

        $sessionManager->blockDevice(
            $challenge->getUser(),
            $challenge->getDeviceId(),
            'Connexion refusée par e-mail'
        );

        return new Response(
            '<h1>Connexion refusée</h1><p>L’appareil a été bloqué. Si ce n’était pas vous, votre compte est protégé.</p>'
        );
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

