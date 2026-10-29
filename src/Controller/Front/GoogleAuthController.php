<?php

declare(strict_types=1);

namespace App\Controller\Front;

use App\Entity\User;
use App\Service\GoogleAccountService;
use App\Service\GoogleOAuthService;
use App\Service\SessionManager;
use App\Service\UserLoginService;
use App\Service\UserRegistrationService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GoogleAuthController extends AbstractController
{
    public function __construct(
        private readonly GoogleOAuthService $google,
        private readonly GoogleAccountService $accounts,
        private readonly UserLoginService $login,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/auth/google', name: 'auth_google_start', methods: ['POST'])]
    public function start(Request $request, UserRegistrationService $registrations, RateLimiterFactory $authLoginLimiter): Response
    {
        if (!$this->isCsrfTokenValid('google_oauth_start', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            if (!$authLoginLimiter->create('google|'.($request->getClientIp() ?? 'unknown'))->consume(1)->isAccepted()) {
                throw new \RuntimeException('auth.google.too_many');
            }
            $company = $registrations->companyFor($request->request->all());

            return $this->oauthRedirect($this->google->begin($request, [
                'company' => $company, 'next' => UserLoginService::safeNext($request->request->get('next')),
            ]));
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $this->addFlash('error', $this->translator->trans($exception->getMessage()));

            return $this->redirectToRoute('show_register');
        }
    }

    #[Route('/app/settings/google/connect', name: 'auth_google_link', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function link(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('google_oauth_start', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        /** @var User $user */
        $user = $this->getUser();

        return $this->oauthRedirect($this->google->begin($request, ['linkUserId' => $user->getId()]));
    }

    #[Route('/auth/google/callback', name: 'auth_google_callback', methods: ['GET'])]
    public function callback(Request $request, SessionManager $sessions): Response
    {
        try {
            $result = $this->google->finish($request);
            $context = $result['context'];
            if (isset($context['linkUserId'])) {
                $token = $request->cookies->get('AUTH_TOKEN');
                $current = is_string($token) ? $sessions->findActiveSessionByPlainToken($token)?->getUser() : null;
                if (null === $current || $current->getId() !== $context['linkUserId']) {
                    throw new \RuntimeException('auth.google.link_required');
                }
                $this->accounts->link($current, $result['identity']);
                $this->addFlash('success', $this->translator->trans('auth.google.linked'));

                return $this->redirectToRoute('app_user_preferences');
            }
            [$user, $new] = $this->accounts->resolve($result['identity'], $request, $context['company'] ?? null);
            $request->getSession()->set('_google_auth_next', $context['next'] ?? null);
            $login = $this->login->complete($user, $request, $new);
            if ($login->getStatusCode() >= 400) {
                throw new \RuntimeException('auth.google.login_blocked');
            }
            $data = json_decode($login->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $response = $this->oauthRedirect(202 === $login->getStatusCode()
                ? $this->generateUrl('app_pending_login') : $data['redirectUrl']);
            foreach ($login->headers->getCookies() as $cookie) {
                $response->headers->setCookie($cookie);
            }

            return $response;
        } catch (\Throwable $exception) {
            $request->getSession()->remove('_google_auth_next');
            $key = str_starts_with($exception->getMessage(), 'auth.google.') ? $exception->getMessage() : 'auth.google.failed';
            $this->logger->warning('Google sign-in failed.', ['type' => $exception::class, 'reason' => $key]);
            $this->addFlash('error', $this->translator->trans($key));

            return $this->redirectToRoute('show_login');
        }
    }

    private function oauthRedirect(string $url): RedirectResponse
    {
        return new RedirectResponse($url, headers: ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
