<?php

namespace App\Controller\Api\Extention;

use App\Entity\Credential;
use App\Entity\ExtensionClient;
use App\Entity\User;
use App\Repository\CredentialRepository;
use App\Repository\SharedAccessRepository;
use App\Repository\TeamRepository;
use App\Repository\UserRepository;
use App\Service\EncryptionService;
use App\Service\ExtensionClientManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

class ApiSharedController extends AbstractController
{
    public function __construct(
        private EncryptionService $encryptionService,
        private UserRepository $userRepository,
        private RateLimiterFactory $extensionApiLimiter,
        private ExtensionClientManager $extensionClientManager,
    ) {}

    private function cors(JsonResponse $res): JsonResponse
    {
        $origin = $_ENV['EXTENSION_ORIGIN'] ?? '';
        if ($origin !== '') {
            $res->headers->set('Access-Control-Allow-Origin', $origin);
            $res->headers->set('Vary', 'Origin');
        }

        $res->headers->set(
            'Access-Control-Allow-Headers',
            'Authorization, Content-Type, X-Extension-Client-Id, X-Extension-Version, X-Extension-Manifest-Version, X-Device-Label, X-Browser-Name, X-Browser-Version, X-OS-Name, X-OS-Version, X-Extension-Origin'
        );
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

    private function forbidden(string $msg): JsonResponse
    {
        return $this->cors($this->json(['error' => $msg], Response::HTTP_FORBIDDEN));
    }

    private function rateLimit(Request $request, string $tokenKey): ?JsonResponse
    {
        $ip = (string) ($request->getClientIp() ?? 'unknown');
        $limiter = $this->extensionApiLimiter->create($tokenKey . '|' . $ip);
        $limit = $limiter->consume(1);

        if (!$limit->isAccepted()) {
            $res = $this->json(['error' => 'Too many requests'], Response::HTTP_TOO_MANY_REQUESTS);

            $retryAfter = $limit->getRetryAfter();
            if ($retryAfter instanceof \DateTimeInterface) {
                $seconds = max(1, $retryAfter->getTimestamp() - time());
                $res->headers->set('Retry-After', (string) $seconds);
            }

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

    /**
     * @return array{user: User, client: ExtensionClient}|array{rate_limited: JsonResponse}|null
     */
    private function authenticate(Request $request): ?array
    {
        $token = $this->getBearerToken($request);
        if (!$token) {
            return null;
        }

        if ($rl = $this->rateLimit($request, $token)) {
            return ['rate_limited' => $rl];
        }

        $user = $this->userRepository->findOneBy(['apiExtensionToken' => $token]);
        if (!$user instanceof User) {
            return null;
        }

        $this->encryptionService->setKeyFromUserToken((string) $user->getApiExtensionToken());

        try {
            $client = $this->extensionClientManager->resolveFromRequest($user, $request);
            $this->extensionClientManager->assertAllowed($client);
        } catch (\RuntimeException $e) {
            return ['rate_limited' => $this->forbidden($e->getMessage())];
        }

        return [
            'user' => $user,
            'client' => $client,
        ];
    }

    #[Route('/extention/api/search', name: 'api_credential_search', methods: ['POST', 'OPTIONS'])]
    public function apiSearch(Request $request, CredentialRepository $credentialRepository): JsonResponse
    {
        if ($pf = $this->preflight($request)) {
            return $pf;
        }

        $auth = $this->authenticate($request);
        if (!$auth) {
            return $this->unauthorized('Token manquant ou invalide');
        }
        if (isset($auth['rate_limited'])) {
            return $auth['rate_limited'];
        }

        /** @var User $user */
        $user = $auth['user'];

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

        $result = array_map(fn (Credential $c) => [
            'id'       => $c->getId(),
            'domain'   => $c->getDomain(),
            'username' => $c->getUsername(),
            'name'     => $c->getName(),
        ], $credentials);

        return $this->cors($this->json(['credentials' => $result]));
    }

    #[Route('/extention/api/credentials/list', name: 'api_credential_list', methods: ['GET', 'OPTIONS'])]
    public function apiCredentials(
        Request $request,
        CredentialRepository $credentialRepository,
        SharedAccessRepository $sharedAccessRepo,
        TeamRepository $teamRepo
    ): JsonResponse {
        if ($pf = $this->preflight($request)) {
            return $pf;
        }

        $auth = $this->authenticate($request);
        if (!$auth) {
            return $this->unauthorized('Token manquant ou invalide');
        }
        if (isset($auth['rate_limited'])) {
            return $auth['rate_limited'];
        }

        /** @var User $user */
        $user = $auth['user'];

        $credentials = $credentialRepository->findCredentialsByUser($user);
        $sharedAccess = $sharedAccessRepo->findSharedWith($user);
        $teams = $teamRepo->findTeamWithCredentialsByUser($user);

        $userPayload = static fn(User $u) => [
            'id' => $u->getId(),
            'email' => $u->getEmail(),
        ];

        $credentialPayload = static fn(Credential $c) => [
            'id' => $c->getId(),
            'domain' => $c->getDomain(),
            'username' => $c->getUsername(),
            'name' => $c->getName(),
        ];

        $resultCredentials = array_map(
            fn(Credential $c) => $credentialPayload($c),
            $credentials
        );

        $resultSharedAccess = array_map(function ($sa) use ($userPayload, $credentialPayload) {
            return [
                'id' => $sa->getId(),
                'createdAt' => $sa->getCreatedAt()?->format(DATE_ATOM),
                'owner' => $userPayload($sa->getOwner()),
                'guest' => $userPayload($sa->getGuest()),
                'credential' => $credentialPayload($sa->getCredential()),
            ];
        }, $sharedAccess);

        $resultTeamSharedAccess = array_map(function ($t) use ($userPayload, $credentialPayload) {
            $members = [];
            foreach ($t->getMembers() as $tm) {
                $members[] = $userPayload($tm->getUser());
            }

            $teamCredentials = [];
            foreach ($t->getCredentials() as $c) {
                $teamCredentials[] = $credentialPayload($c);
            }

            return [
                'team' => [
                    'id' => $t->getId(),
                    'name' => $t->getName(),
                    'createdAt' => $t->getCreatedAt()->format(DATE_ATOM),
                    'owner' => $userPayload($t->getOwner()),
                    'members' => $members,
                ],
                'credentials' => $teamCredentials,
            ];
        }, $teams);

        return $this->cors($this->json([
            'credentials' => $resultCredentials,
            'sharedAccess' => $resultSharedAccess,
            'teamSharedAccess' => $resultTeamSharedAccess,
        ]));
    }

    #[Route('/extention/api/credentials/{id}/reveal', name: 'api_credential_reveal', methods: ['POST', 'OPTIONS'])]
    public function reveal(
        Request $request,
        int $id,
        CredentialRepository $credentialRepository,
        SharedAccessRepository $sharedAccessRepo,
        TeamRepository $teamRepo
    ): JsonResponse {
        if ($pf = $this->preflight($request)) {
            return $pf;
        }

        $auth = $this->authenticate($request);
        if (!$auth) {
            return $this->unauthorized('Token manquant ou invalide');
        }
        if (isset($auth['rate_limited'])) {
            return $auth['rate_limited'];
        }

        /** @var User $user */
        $user = $auth['user'];

        $cred = $credentialRepository->find($id);
        if (!$cred) {
            return $this->cors($this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND));
        }

        $isOwner = ($cred->getUser()?->getId() === $user->getId());
        $hasDirectShare = $sharedAccessRepo->userHasAccessToCredential($user, $cred);
        $hasTeamShare = $teamRepo->userHasTeamAccessToCredential($user, $cred);

        if (!$isOwner && !$hasDirectShare && !$hasTeamShare) {
            return $this->cors($this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND));
        }

        $owner = $cred->getUser();
        if (!$owner || !$owner->getApiExtensionToken()) {
            return $this->cors($this->json(['error' => 'Owner key missing'], Response::HTTP_CONFLICT));
        }

        $this->encryptionService->setKeyFromUserToken($owner->getApiExtensionToken());
        $password = $this->encryptionService->decrypt((string) $cred->getPassword());

        return $this->cors($this->json([
            'id' => $cred->getId(),
            'password' => $password,
        ]));
    }

    #[Route('/extention/api/credentials/create', name: 'api_credential_create', methods: ['POST', 'OPTIONS'])]
    public function createCredential(
        Request $request,
        CredentialRepository $credentialRepository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        if ($pf = $this->preflight($request)) {
            return $pf;
        }

        $auth = $this->authenticate($request);
        if (!$auth) {
            return $this->unauthorized('Token manquant ou invalide');
        }
        if (isset($auth['rate_limited'])) {
            return $auth['rate_limited'];
        }

        /** @var User $user */
        $user = $auth['user'];

        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->badRequest('Corps JSON invalide');
        }

        $domain = $payload['domain'] ?? null;
        $username = $payload['username'] ?? null;
        $password = $payload['password'] ?? null;
        $name = $payload['name'] ?? null;

        if (!is_string($domain) || trim($domain) === '') {
            return $this->badRequest('Domaine manquant ou invalide');
        }

        if (!is_string($username) || trim($username) === '') {
            return $this->badRequest('Nom d\'utilisateur manquant ou invalide');
        }

        if (!is_string($password) || trim($password) === '') {
            return $this->badRequest('Mot de passe manquant ou invalide');
        }

        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = preg_replace('#^www\.#', '', $domain);
        $domain = strtolower(trim($domain));

        if (!$name || trim($name) === '') {
            $name = $domain . ' - ' . $username;
        }

        $existingCredential = $credentialRepository->findOneBy([
            'user' => $user,
            'domain' => $domain,
            'username' => $username,
        ]);

        if ($existingCredential) {
            return $this->cors($this->json([
                'error' => 'Un credential existe déjà pour ce domaine et cet utilisateur',
                'credentialId' => $existingCredential->getId(),
            ], Response::HTTP_CONFLICT));
        }

        $encryptedPassword = $this->encryptionService->encrypt($password);

        $credential = new Credential();
        $credential->setName($name);
        $credential->setDomain($domain);
        $credential->setUsername($username);
        $credential->setPassword($encryptedPassword);
        $credential->setUser($user);

        $entityManager->persist($credential);
        $entityManager->flush();

        return $this->cors($this->json([
            'success' => true,
            'credential' => [
                'id' => $credential->getId(),
                'name' => $credential->getName(),
                'domain' => $credential->getDomain(),
                'username' => $credential->getUsername(),
                'createdAt' => $credential->getCreatedAt()?->format(DATE_ATOM),
            ],
        ], Response::HTTP_CREATED));
    }
}