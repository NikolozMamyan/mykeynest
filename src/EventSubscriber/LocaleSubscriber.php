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
        $request = $event->getRequest();

        // 1) Query param ?lang=en
        $lang = $request->query->get('lang');
        if (is_string($lang) && in_array($lang, $this->supportedLocales, true)) {
            $request->setLocale($lang);

            // (optionnel) persister en session
            if ($request->hasSession()) {
                $request->getSession()->set('_locale', $lang);
            }
            return;
        }

        // 2) Si pas de lang dans l'URL, on reprend la session si dispo
        if ($request->hasSession()) {
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
