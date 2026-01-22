<?php

namespace App\EventSubscriber;

use App\Repository\UserRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AppAuthSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private UserRepository $users,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Ne protège que /app (pas /api)
        if (!str_starts_with($path, '/app')) {
            return;
        }

        // Autoriser la page login elle-même (et assets éventuels)
        if ($path === '/app/login' || str_starts_with($path, '/app/assets')) {
            return;
        }

        $token = $request->cookies->get('AUTH_TOKEN');
        if (!$token) {
            $event->setResponse($this->redirectToLogin($request));
            return;
        }

        $user = $this->users->findOneBy(['apiToken' => $token]);
        if (!$user) {
            $event->setResponse($this->redirectToLogin($request));
            return;
        }

        // (Optionnel) check expiration
        if ($user->getTokenExpiresAt() && $user->getTokenExpiresAt() < new \DateTimeImmutable()) {
            $event->setResponse($this->redirectToLogin($request));
            return;
        }

        // Ici tu peux mettre le user dans un attribut request si besoin
        // $request->attributes->set('auth_user', $user);
    }

    private function redirectToLogin($request): RedirectResponse
    {
        $next = $request->getRequestUri(); // path + query
        $url = '/login?next=' . rawurlencode($next);
        return new RedirectResponse($url);
    }
}
