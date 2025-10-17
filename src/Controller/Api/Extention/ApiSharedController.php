<?php

namespace App\Controller\Api\Extention;

use App\Entity\Credential;
use App\Repository\UserRepository;
use App\Repository\CredentialRepository;
use App\Service\EncryptionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class ApiSharedController extends AbstractController
{
    public function __construct(
        private EncryptionService $encryptionService,
        private UserRepository $userRepository,
    ) {
    }

    #[Route('/extention/api/search', name: 'api_credential_search', methods: ['GET', 'POST', 'OPTIONS'])]
    public function apiSearch(
        Request $request,
        CredentialRepository $credentialRepository
    ): JsonResponse {
        // ✅ Gérer les requêtes preflight CORS
        if ($request->getMethod() === 'OPTIONS') {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        // ✅ Récupérer le token de l’en-tête Authorization
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader || !preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
            return $this->json(['error' => 'Token manquant'], Response::HTTP_UNAUTHORIZED);
        }

        $apiExtensionToken = $matches[1];

        // ✅ Trouver l’utilisateur correspondant
        $user = $this->userRepository->findOneBy(['apiExtensionToken' => $apiExtensionToken]);
        if (!$user) {
            return $this->json(['error' => 'Token invalide'], Response::HTTP_UNAUTHORIZED);
        }

        // ✅ Mettre à jour la clé de chiffrement du service avec le token de cet utilisateur
        $this->encryptionService->setEncryptionKey($user->getApiExtensionToken());

        // ✅ Lire le corps JSON
        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->json(['error' => 'Corps JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $domain = $payload['domain'] ?? null;
        if (!$domain) {
            return $this->json(['error' => 'Domaine non spécifié'], Response::HTTP_BAD_REQUEST);
        }

        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = preg_replace('#^www\.#', '', $domain);

        // ✅ Recherche dans les credentials de cet utilisateur
        $credentials = $credentialRepository->findByDomainAndUser($domain, $user);

        $result = array_map(fn (Credential $c) => [
            'id'       => $c->getId(),
            'domain'   => $c->getDomain(),
            'username' => $c->getUsername(),
            'password' => $this->encryptionService->decrypt($c->getPassword()),
            'name'     => $c->getName(),
        ], $credentials);

        return $this->json(['credentials' => $result]);
    }
}
