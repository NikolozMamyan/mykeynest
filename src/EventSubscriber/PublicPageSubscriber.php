<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class PublicPageSubscriber implements EventSubscriberInterface
{
    public const ROUTES = [
        'app_landing', 'app_public_generator', 'app_help_center', 'app_help_category',
        'app_help_article', 'business_solution', 'business_password_manager',
        'business_comparatif', 'business_audit', 'legal_cgu', 'legal_cgv', 'legal_privacy',
        'legal_mentions', 'app_security', 'blog_index', 'blog_article_show', 'sitemap',
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['redirectCanonicalHost', 36],
            KernelEvents::RESPONSE => ['cacheAnonymousPages', -1100],
        ];
    }

    public function redirectCanonicalHost(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getRequest()->getHost() !== 'www.key-nest.com') {
            return;
        }

        $request = $event->getRequest();
        $event->setResponse(new RedirectResponse(
            'https://key-nest.com'.$request->getRequestUri(),
            $request->isMethodSafe() ? 301 : 308,
        ));
    }

    public function cacheAnonymousPages(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        if (!in_array($request->attributes->get('_route'), self::ROUTES, true)) {
            return;
        }

        $response->setVary(['Cookie', 'Authorization'], false);
        if (!$request->isMethodSafe() || !$response->isOk() || $request->cookies->count() > 0
            || $request->headers->has('Authorization') || $response->headers->getCookies()
            || ($request->hasSession() && $request->getSession()->isStarted())) {
            $response->setPrivate();

            return;
        }

        $response->setPublic();
        $response->setMaxAge(300);
        $response->setSharedMaxAge(300);
    }
}
