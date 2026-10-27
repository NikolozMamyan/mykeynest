<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class LocaleSubscriber implements EventSubscriberInterface
{
    public const COOKIE_NAME = 'MYKEYNEST_LOCALE';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly string $defaultLocale = 'en',
        private readonly array $supportedLocales = ['fr', 'en'],
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
            KernelEvents::RESPONSE => ['onKernelResponse', -1000],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $isPublicPage = in_array($request->attributes->get('_route'), PublicPageSubscriber::ROUTES, true);
        if (in_array($request->attributes->get('_route'), ['legal_cgu', 'legal_cgv'], true)) {
            $request->setLocale('fr');
            return;
        }

        $lang = $request->query->all()['lang'] ?? null;
        $routeLocale = $request->attributes->get('_locale');
        if (is_string($routeLocale) && in_array($routeLocale, $this->supportedLocales, true)) {
            $request->setLocale($routeLocale);
            if ($lang === $routeLocale) {
                $request->attributes->set('_locale_preference', $routeLocale);
            }
            if (!$isPublicPage && $request->hasSession()) {
                $request->getSession()->set('_locale', $routeLocale);
            }
            return;
        }

        if (is_string($lang) && in_array($lang, $this->supportedLocales, true)) {
            $request->setLocale($lang);
            $request->attributes->set('_locale_preference', $lang);
            if (!$isPublicPage && $request->hasSession()) {
                $request->getSession()->set('_locale', $lang);
            }
            return;
        }

        $cookieLocale = $request->cookies->get(self::COOKIE_NAME);
        if (is_string($cookieLocale) && in_array($cookieLocale, $this->supportedLocales, true)) {
            $request->setLocale($cookieLocale);
            return;
        }

        if (!$isPublicPage && $request->hasPreviousSession()) {
            $stored = $request->getSession()->get('_locale');
            if (is_string($stored) && in_array($stored, $this->supportedLocales, true)) {
                $request->setLocale($stored);
                return;
            }
        }

        foreach (AcceptHeader::fromString($request->headers->get('Accept-Language'))->all() as $language) {
            $locale = strtolower(explode('-', str_replace('_', '-', $language->getValue()))[0]);
            if ($language->getQuality() > 0 && in_array($locale, $this->supportedLocales, true)) {
                $request->setLocale($locale);
                return;
            }
        }

        $request->setLocale($this->defaultLocale);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $locale = $request->attributes->get('_locale_preference');
        if (!$request->isMethod('GET') || $response->getStatusCode() >= 400
            || !is_string($locale) || !in_array($locale, $this->supportedLocales, true)
            || $request->cookies->get(self::COOKIE_NAME) === $locale) {
            return;
        }

        $response->headers->setCookie(Cookie::create(self::COOKIE_NAME)
            ->withValue($locale)
            ->withExpires(new \DateTimeImmutable('+1 year'))
            ->withPath('/')
            ->withHttpOnly(true)
            ->withSecure($request->isSecure())
            ->withSameSite(Cookie::SAMESITE_LAX));
        $response->setPrivate();
        $response->setMaxAge(0);
    }
}
