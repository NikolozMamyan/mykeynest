<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly string $defaultLocale = 'fr',
        private readonly array $supportedLocales = ['fr', 'en'],
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Priorité haute pour être exécuté tôt
        return [KernelEvents::REQUEST => ['onKernelRequest', 20]];
    }

 public function onKernelRequest(RequestEvent $event): void
{
    if (!$event->isMainRequest()) {
        return;
    }

    $request = $event->getRequest();
    $isPublicPage = in_array($request->attributes->get('_route'), PublicPageSubscriber::ROUTES, true);
    if (in_array($request->attributes->get('_route'), ['legal_cgu', 'legal_cgv', 'legal_privacy'], true)) {
        $request->setLocale('fr');
        return;
    }

    // 0) Si une route a déjà _locale, on respecte
    if ($request->attributes->has('_locale')) {
        $locale = $request->attributes->get('_locale');
        if (is_string($locale) && in_array($locale, $this->supportedLocales, true)) {
            $request->setLocale($locale);
            if (!$isPublicPage && $request->hasSession()) {
                $request->getSession()->set('_locale', $locale);
            }
            return;
        }
    }

    // 1) Query param ?lang=en
    $lang = $request->query->get('lang');
    if (is_string($lang) && in_array($lang, $this->supportedLocales, true)) {
        $request->setLocale($lang);
        if (!$isPublicPage && $request->hasSession()) {
            $request->getSession()->set('_locale', $lang);
        }
        return;
    }

    // 2) Session
    if (!$isPublicPage && $request->hasPreviousSession()) {
        $stored = $request->getSession()->get('_locale');
        if (is_string($stored) && in_array($stored, $this->supportedLocales, true)) {
            $request->setLocale($stored);
            return;
        }
    }

    // 3) fallback
    $request->setLocale($this->defaultLocale);
}

}
