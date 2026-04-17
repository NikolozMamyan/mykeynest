<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class AuthTokenRefreshSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $refresh = $request->attributes->get('_auth_token_refresh');

        if (!is_array($refresh)) {
            return;
        }

        $plainToken = $refresh['plainToken'] ?? null;
        $expiresAt = $refresh['expiresAt'] ?? null;

        if (!is_string($plainToken) || trim($plainToken) === '' || !$expiresAt instanceof \DateTimeInterface) {
            return;
        }

        $event->getResponse()->headers->setCookie($this->buildAuthCookie($request, trim($plainToken), $expiresAt));
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
}
