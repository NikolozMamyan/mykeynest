<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\ExtensionOnboardingPolicy;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ExtensionOnboardingSubscriber implements EventSubscriberInterface
{
    private const ALLOWED_ROUTES = [
        'app_extention',
        'app_extension_onboarding_defer',
    ];

    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
        private ExtensionOnboardingPolicy $extensionOnboardingPolicy
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'redirectPendingUser'];
    }

    public function redirectPendingUser(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/app/')) {
            return;
        }

        $route = (string) $request->attributes->get('_route', '');
        if (in_array($route, self::ALLOWED_ROUTES, true)) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User
            || in_array('ROLE_ADMIN', $user->getRoles(), true)
            || !$this->extensionOnboardingPolicy->isRequiredFor($user)
        ) {
            return;
        }

        $url = $this->urlGenerator->generate('app_extention', ['onboarding' => 1]);
        $event->setController(static fn (): RedirectResponse => new RedirectResponse($url));
    }
}
