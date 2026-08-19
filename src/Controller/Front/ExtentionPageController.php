<?php

namespace App\Controller\Front;

use App\Entity\User;
use App\Service\ExtensionOnboardingPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ExtentionPageController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(EXTENSION_ID)%')]
        private string $extensionId,
        #[Autowire('%env(EXTENSION_WEBSTORE_URL)%')]
        private string $chromeWebStoreUrl,
        private ExtensionOnboardingPolicy $extensionOnboardingPolicy
    ) {
    }

    #[Route('/app/extention', name: 'app_extention', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        $isReconnect = $request->query->getBoolean('reconnect');

        if ($this->extensionOnboardingPolicy->isRequiredFor($user) || $isReconnect) {
            return $this->noStore($this->render('extention/onboarding.html.twig', [
                'chromeWebStoreUrl' => $this->chromeWebStoreUrl,
                'extensionId' => $this->extensionId,
                'isMobileDevice' => $this->isMobileDevice($request),
                'hideAppChrome' => true,
                'isReconnect' => $isReconnect,
            ]));
        }

        if ($request->query->getBoolean('onboarding')) {
            return $this->noStore($this->redirectToRoute('app_dashboard'));
        }

        return $this->noStore($this->render('extention/index.html.twig', [
            'extentiotoken' => $user->getApiExtensionToken(),
            'chromeWebStoreUrl' => $this->chromeWebStoreUrl,
            'isOnboarding' => (bool) $request->query->get('onboarding'),
            'autoCopyToken' => false,
        ]));
    }

    #[Route('/app/extention/defer', name: 'app_extension_onboarding_defer', methods: ['POST'])]
    public function defer(Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        if (!$this->isCsrfTokenValid('extension_onboarding_defer', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if (!$this->isMobileDevice($request)) {
            throw $this->createAccessDeniedException('Le report est reserve aux appareils mobiles.');
        }

        $user->deferExtensionOnboarding();
        $entityManager->flush();
        $this->addFlash('info', 'Installation de l extension a terminer depuis un ordinateur.');

        return $this->redirectToRoute('app_credential');
    }

    private function isMobileDevice(Request $request): bool
    {
        $userAgent = strtolower((string) $request->headers->get('User-Agent', ''));

        return preg_match('/android|iphone|ipad|ipod|mobile/', $userAgent) === 1;
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
