<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\VisitorRecorder;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class VisitorTrackingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private VisitorRecorder $recorder,
        private LoggerInterface $logger,
        #[Autowire('%env(bool:VISITOR_TRACKING_ENABLED)%')]
        private bool $enabled,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'track'];
    }

    public function track(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        $route = $request->attributes->get('_route');
        if (!$this->enabled || !$request->isMethod('GET') || !$response->isOk()
            || !str_contains($response->headers->get('Content-Type', ''), 'text/html')
            || (!in_array($route, PublicPageSubscriber::ROUTES, true) && !in_array($route, ['show_register', 'app_install_app'], true))
            || in_array($route, ['sitemap', 'legal_privacy'], true)
            || $request->headers->has('Turbo-Frame') || $request->isXmlHttpRequest()
            || str_contains(strtolower($request->headers->get('Sec-Purpose', '').$request->headers->get('Purpose', '')), 'prefetch')
            || preg_match('/bot|spider|crawler|slurp|headless|lighthouse|curl|wget/i', $request->headers->get('User-Agent', ''))) {
            return;
        }

        try {
            $this->recorder->record($request);
        } catch (\Throwable $exception) {
            $this->logger->warning('Visitor tracking failed.', ['exception' => $exception]);
        }
    }
}
