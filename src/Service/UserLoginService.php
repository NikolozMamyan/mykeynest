<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class UserLoginService
{
    public function __construct(
        private SessionManager $sessions,
        private LoginChallengeManager $challenges,
        private EmailTwoFactorPolicy $twoFactor,
        private ExtensionOnboardingPolicy $onboarding,
        private MailerService $mailer,
        private UrlGeneratorInterface $urls,
        private LoggerInterface $logger,
    ) {
    }

    public function complete(User $user, Request $request, bool $newAccount = false): JsonResponse
    {
        $deviceId = $this->sessions->getOrCreateCurrentDeviceId();
        $state = $this->sessions->getDeviceAccessState($user, $deviceId);
        if (UserDeviceManager::STATE_BLOCKED === $state) {
            return new JsonResponse(['error' => 'Connexion bloquée', 'message' => 'Cet appareil a été bloqué. Utilisez un autre appareil ou débloquez-le depuis vos paramètres de sécurité.'], 403);
        }
        $twoFactor = !$newAccount && $this->twoFactor->isEnabledFor($user);
        if (UserDeviceManager::STATE_TRUSTED === $state || !$twoFactor) {
            try {
                [$session, $token, $deviceId] = $this->sessions->createSession($user, deviceId: $deviceId, trustUnknownDevice: !$twoFactor);
            } catch (\RuntimeException $exception) {
                return new JsonResponse(['error' => 'Connexion bloquée', 'message' => $exception->getMessage()], 403);
            }
            $response = new JsonResponse([
                'message' => $newAccount ? 'Inscription réussie' : 'Connexion réussie',
                'user' => ['id' => $user->getId(), 'email' => $user->getEmail(), 'roles' => $user->getRoles()],
                'session' => ['id' => $session->getId(), 'expiresAt' => $session->getExpiresAt()->format(DATE_ATOM)],
                'redirectUrl' => $this->redirectUrl($user, $request),
            ], $newAccount ? 201 : 200);
            $response->headers->setCookie($this->cookie('AUTH_TOKEN', $token, $request, $session->getExpiresAt()));
            $response->headers->setCookie($this->cookie(DeviceIdentifier::COOKIE_NAME, $deviceId, $request, new \DateTimeImmutable('+5 years')));

            return $response;
        }

        [$challenge, $token] = $this->challenges->createChallenge($user, $deviceId);
        try {
            $this->mailer->send($user->getEmail(), 'Validez cette connexion', 'emails/security/login_challenge.html.twig', [
                'user' => $user,
                'approveUrl' => $this->urls->generate('api_login_challenge_approve', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
                'rejectUrl' => $this->urls->generate('api_login_challenge_reject', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
                'ip' => $request->getClientIp(), 'userAgent' => $request->headers->get('User-Agent'), 'requestedAt' => new \DateTimeImmutable(),
            ]);
        } catch (\Throwable $exception) {
            $this->challenges->expire($challenge);
            $this->logger->error('Failed to send login challenge email', ['userId' => $user->getId(), 'challengeId' => $challenge->getPublicId(), 'error' => $exception->getMessage()]);
            $response = new JsonResponse(['error' => 'Validation temporairement indisponible', 'message' => 'L’e-mail de validation n’a pas pu être envoyé. Veuillez réessayer dans quelques minutes.'], 503);
            $response->headers->setCookie($this->cookie(DeviceIdentifier::COOKIE_NAME, $deviceId, $request, new \DateTimeImmutable('+5 years')));

            return $response;
        }
        $response = new JsonResponse([
            'status' => 'email_verification_required', 'message' => 'Nous avons envoyé un e-mail pour valider cette connexion.',
            'challenge' => ['publicId' => $challenge->getPublicId(), 'expiresAt' => $challenge->getExpiresAt()?->format(DATE_ATOM)],
        ], 202);
        $response->headers->setCookie($this->cookie(LoginChallengeManager::COOKIE_NAME, $token, $request, new \DateTimeImmutable('+15 minutes')));
        $response->headers->setCookie($this->cookie(DeviceIdentifier::COOKIE_NAME, $deviceId, $request, new \DateTimeImmutable('+5 years')));

        return $response;
    }

    public function redirectUrl(User $user, Request $request): string
    {
        $next = $request->hasSession() ? $request->getSession()->remove('_google_auth_next') : null;
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $this->urls->generate('app_admin');
        }
        if ($this->onboarding->isRequiredFor($user)) {
            return $this->urls->generate('app_extention', ['onboarding' => 1]);
        }

        return self::safeNext($next) ?? '/app/credential';
    }

    public static function safeNext(mixed $next): ?string
    {
        return is_string($next) && str_starts_with($next, '/') && !str_starts_with($next, '//')
            && !preg_match('/[\\\\\x00-\x20]/', $next) ? $next : null;
    }

    private function cookie(string $name, string $value, Request $request, \DateTimeInterface $expires): Cookie
    {
        return Cookie::create($name)->withValue($value)->withPath('/')->withHttpOnly(true)
            ->withSecure($request->isSecure())->withSameSite(Cookie::SAMESITE_LAX)->withExpires($expires);
    }
}
