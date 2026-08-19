<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\ExtensionPairingManager;
use App\Service\ExtensionOnboardingPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/extension-pairing')]
final class ExtensionPairingController extends AbstractController
{
    public function __construct(
        private RateLimiterFactory $extensionPairingLimiter,
        private ExtensionOnboardingPolicy $extensionOnboardingPolicy,
        #[Autowire('%env(EXTENSION_ORIGIN)%')]
        private string $extensionOrigin
    ) {
    }

    #[Route('/status', name: 'api_extension_pairing_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->noStore($this->json(['error' => 'Non authentifie.'], Response::HTTP_UNAUTHORIZED));
        }

        return $this->noStore($this->json([
            'completed' => !$this->extensionOnboardingPolicy->isRequiredFor($user),
            'redirectUrl' => $this->generateUrl('app_dashboard'),
        ]));
    }

    #[Route('/start', name: 'api_extension_pairing_start', methods: ['POST'])]
    public function start(Request $request, ExtensionPairingManager $pairingManager): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifie.'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->isCsrfTokenValid('extension_pairing_start', (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return $this->json(['error' => 'Jeton CSRF invalide.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $content = trim($request->getContent());
            $data = $content === '' ? [] : json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            $clientId = is_array($data) ? (string) ($data['clientId'] ?? '') : '';
            $isReconnect = is_array($data) && ($data['reconnect'] ?? false) === true;
        } catch (\JsonException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->extensionOnboardingPolicy->isRequiredFor($user) && !$isReconnect) {
            return $this->json([
                'success' => true,
                'completed' => true,
                'redirectUrl' => $this->generateUrl('app_dashboard'),
            ]);
        }

        if ($limited = $this->consumeRateLimit($request, 'start|' . $user->getId())) {
            return $limited;
        }

        try {
            $result = $pairingManager->issue($user, $clientId);
        } catch (\RuntimeException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'pairingToken' => $result['plainToken'],
            'challenge' => [
                'publicId' => $result['challenge']->getPublicId(),
                'expiresAt' => $result['challenge']->getExpiresAt()->format(DATE_ATOM),
            ],
        ], Response::HTTP_CREATED);
    }

    #[Route('/exchange', name: 'api_extension_pairing_exchange', methods: ['POST', 'OPTIONS'])]
    public function exchange(Request $request, ExtensionPairingManager $pairingManager): JsonResponse
    {
        if ($request->isMethod(Request::METHOD_OPTIONS)) {
            return $this->withExtensionCors(new JsonResponse(null, Response::HTTP_NO_CONTENT), $request);
        }

        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $pairingToken = is_array($data) ? (string) ($data['pairingToken'] ?? '') : '';
        } catch (\JsonException) {
            return $this->withExtensionCors($this->json(['error' => 'Corps JSON invalide.'], Response::HTTP_BAD_REQUEST), $request);
        }

        if ($limited = $this->consumeRateLimit($request, 'exchange|' . hash('sha256', $pairingToken))) {
            return $this->withExtensionCors($limited, $request);
        }

        try {
            $result = $pairingManager->exchange($pairingToken, $request);
        } catch (\RuntimeException $exception) {
            return $this->withExtensionCors($this->json([
                'error' => $exception->getMessage(),
            ], Response::HTTP_BAD_REQUEST), $request);
        }

        return $this->withExtensionCors($this->json([
            'apiToken' => $result['apiToken'],
            'installationToken' => $result['installationToken'],
            'clientId' => $result['challenge']->getClientId(),
        ]), $request);
    }

    #[Route('/complete', name: 'api_extension_pairing_complete', methods: ['POST'])]
    public function complete(Request $request, ExtensionPairingManager $pairingManager): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifie.'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->isCsrfTokenValid('extension_pairing_complete', (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return $this->json(['error' => 'Jeton CSRF invalide.'], Response::HTTP_FORBIDDEN);
        }

        if ($limited = $this->consumeRateLimit($request, 'complete|' . $user->getId())) {
            return $limited;
        }

        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $pairingManager->complete(
                $user,
                is_array($data) ? (string) ($data['publicId'] ?? '') : '',
                is_array($data) ? (string) ($data['clientId'] ?? '') : ''
            );
        } catch (\JsonException|\RuntimeException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'success' => true,
            'redirectUrl' => $this->generateUrl('app_dashboard'),
        ]);
    }

    private function consumeRateLimit(Request $request, string $key): ?JsonResponse
    {
        $limit = $this->extensionPairingLimiter
            ->create($key . '|' . ($request->getClientIp() ?? 'unknown'))
            ->consume(1);

        if ($limit->isAccepted()) {
            return null;
        }

        return $this->json(['error' => 'Trop de tentatives. Reessayez plus tard.'], Response::HTTP_TOO_MANY_REQUESTS);
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    private function withExtensionCors(JsonResponse $response, Request $request): JsonResponse
    {
        $origin = (string) $request->headers->get('Origin', '');

        if ($origin !== '' && hash_equals($this->extensionOrigin, $origin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Extension-Client-Id, X-Extension-Version, X-Extension-Manifest-Version, X-Device-Label, X-Browser-Name, X-Browser-Version, X-OS-Name, X-OS-Version, X-Extension-Origin');
            $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
            $response->headers->set('Access-Control-Max-Age', '600');
        }

        return $response;
    }
}
