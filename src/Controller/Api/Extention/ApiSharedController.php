<?php

namespace App\Controller\Api\Extention;

use App\Entity\Credential;
use App\Entity\Notification;
use App\Repository\UserRepository;
use App\Repository\CredentialRepository;
use App\Service\EncryptionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\RateLimiter\RateLimiterFactory;

class ApiSharedController extends AbstractController
{
    public function __construct(
        private EncryptionService $encryptionService,
        private UserRepository $userRepository,
        private RateLimiterFactory $extensionApiLimiter, // ✅ rate limit
    ) {}

    private function cors(JsonResponse $res): JsonResponse
    {
        // Exemple: "chrome-extension://abcd1234" (à mettre dans .env)
        $origin = $_ENV['EXTENSION_ORIGIN'] ?? '';
        if ($origin !== '') {
            $res->headers->set('Access-Control-Allow-Origin', $origin);
            $res->headers->set('Vary', 'Origin');
        }

        $res->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type');
        $res->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $res->headers->set('Access-Control-Max-Age', '600');

        return $res;
    }

    private function preflight(Request $request): ?JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return $this->cors(new JsonResponse(null, Response::HTTP_NO_CONTENT));
        }
        return null;
    }

    private function unauthorized(string $msg = 'Unauthorized'): JsonResponse
    {
        return $this->cors($this->json(['error' => $msg], Response::HTTP_UNAUTHORIZED));
    }

    private function badRequest(string $msg): JsonResponse
    {
        return $this->cors($this->json(['error' => $msg], Response::HTTP_BAD_REQUEST));
    }

    private function rateLimit(Request $request, string $tokenKey): ?JsonResponse
    {
        // clé = token + IP (évite bruteforce / scraping)
        $ip = (string) ($request->getClientIp() ?? 'unknown');
        $limiter = $this->extensionApiLimiter->create($tokenKey.'|'.$ip);
        $limit = $limiter->consume(1);

        if (!$limit->isAccepted()) {
            $res = $this->json(['error' => 'Too many requests'], Response::HTTP_TOO_MANY_REQUESTS);
            $res->headers->set('Retry-After', (string) $limit->getRetryAfter()->getTimestamp());
            return $this->cors($res);
        }

        return null;
    }

    private function getBearerToken(Request $request): ?string
    {
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader) {
            return null;
        }
        if (!preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
            return null;
        }
        return $matches[1];
    }

    private function authenticate(Request $request): ?array
    {
        $token = $this->getBearerToken($request);
        if (!$token) {
            return null;
        }

        // ✅ rate limit dès qu'on a un token (même invalide)
        if ($rl = $this->rateLimit($request, $token)) {
            // on renverra directement une réponse 429 au controller
            return ['rate_limited' => $rl];
        }

        $user = $this->userRepository->findOneBy(['apiExtensionToken' => $token]);
        if (!$user) {
            return null;
        }

        // ✅ clé dérivée serveur (protège offline decrypt)
        $this->encryptionService->setKeyFromUserToken($user->getApiExtensionToken());

        return ['user' => $user];
    }

    #[Route('/extention/api/search', name: 'api_credential_search', methods: ['POST', 'OPTIONS'])]
    public function apiSearch(Request $request, CredentialRepository $credentialRepository): JsonResponse
    {
        if ($pf = $this->preflight($request)) return $pf;

        $auth = $this->authenticate($request);
        if (!$auth) return $this->unauthorized('Token manquant ou invalide');
        if (isset($auth['rate_limited'])) return $auth['rate_limited'];

        $user = $auth['user'];

        // ✅ JSON obligatoire
        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->badRequest('Corps JSON invalide');
        }

        $domain = $payload['domain'] ?? null;
        if (!is_string($domain) || trim($domain) === '') {
            return $this->badRequest('Domaine non spécifié');
        }

        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = preg_replace('#^www\.#', '', $domain);
        $domain = strtolower(trim($domain));

        $credentials = $credentialRepository->findByDomainAndUser($domain, $user);

        // ✅ pas de password ici
        $result = array_map(fn (Credential $c) => [
            'id'       => $c->getId(),
            'domain'   => $c->getDomain(),
            'username' => $c->getUsername(),
            'name'     => $c->getName(),
        ], $credentials);

        return $this->cors($this->json(['credentials' => $result]));
    }

    #[Route('/extention/api/credentials/list', name: 'api_credential_list', methods: ['GET', 'OPTIONS'])]
    public function apiCredentials(Request $request, CredentialRepository $credentialRepository): JsonResponse
    {
        if ($pf = $this->preflight($request)) return $pf;

        $auth = $this->authenticate($request);
        if (!$auth) return $this->unauthorized('Token manquant ou invalide');
        if (isset($auth['rate_limited'])) return $auth['rate_limited'];

        $user = $auth['user'];

        $credentials = $credentialRepository->findCredentialsByUser($user);

        // ✅ pas de password
        $result = array_map(fn (Credential $c) => [
            'id'       => $c->getId(),
            'domain'   => $c->getDomain(),
            'username' => $c->getUsername(),
            'name'     => $c->getName(),
        ], $credentials);

        return $this->cors($this->json(['credentials' => $result]));
    }

    #[Route('/extention/api/credentials/{id}/reveal', name: 'api_credential_reveal', methods: ['POST', 'OPTIONS'])]
    public function reveal(Request $request, int $id, CredentialRepository $credentialRepository): JsonResponse
    {
        if ($pf = $this->preflight($request)) return $pf;

        $auth = $this->authenticate($request);
        if (!$auth) return $this->unauthorized('Token manquant ou invalide');
        if (isset($auth['rate_limited'])) return $auth['rate_limited'];

        $user = $auth['user'];

        $cred = $credentialRepository->find($id);
        if (!$cred || $cred->getUser()?->getId() !== $user->getId()) {
            return $this->cors($this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND));
        }

        // ✅ reveal 1 seul secret
$password = $this->encryptionService->decrypt((string) $cred->getPassword());


        return $this->cors($this->json([
            'id' => $cred->getId(),
            'password' => $password,
        ]));
    }
}
