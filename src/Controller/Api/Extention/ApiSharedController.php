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

final class ApiSharedController extends AbstractController
{
    public function __construct(
        private EncryptionService $encryptionService,
        private UserRepository $userRepository,
        private RateLimiterFactory $extensionApiLimiter,
        private ExtensionClientManager $extensionClientManager,
    ) {
    }

    private function cors(JsonResponse $response): JsonResponse
    {
        $origin = $_ENV['EXTENSION_ORIGIN'] ?? '';

        if ($origin !== '') {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
        }

        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Extension-Client-Id, X-Extension-Installation-Token, X-Extension-Version, X-Extension-Manifest-Version, X-Device-Label, X-Browser-Name, X-Browser-Version, X-OS-Name, X-OS-Version, X-Extension-Origin');
        $response->headers->set('Access-Control-Expose-Headers', 'X-Extension-Installation-Token');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Max-Age', '600');

        return $response;
    }

    private function preflight(Request $request): ?JsonResponse
    {
        if ($request->getMethod() === Request::METHOD_OPTIONS) {
            return $this->cors(new JsonResponse(null, Response::HTTP_NO_CONTENT));
        }

        return null;
    }

    private function unauthorized(string $message = 'Unauthorized'): JsonResponse
    {
        return $this->cors($this->json(['error' => $message], Response::HTTP_UNAUTHORIZED));
    }

    private function badRequest(string $message): JsonResponse
    {
        return $this->cors($this->json(['error' => $message], Response::HTTP_BAD_REQUEST));
    }

    private function forbidden(string $message): JsonResponse
    {
        return $this->cors($this->json(['error' => $message], Response::HTTP_FORBIDDEN));
    }

    private function withInstallationToken(JsonResponse $response, ?string $installationToken): JsonResponse
    {
        if ($installationToken) {
            $response->headers->set('X-Extension-Installation-Token', $installationToken);
        }

        return $this->cors($response);
    }

    private function rateLimit(Request $request, string $tokenKey): ?JsonResponse
    {
        $ip = (string) ($request->getClientIp() ?? 'unknown');
        $limiter = $this->extensionApiLimiter->create($tokenKey . '|' . $ip);
        $limit = $limiter->consume(1);

        if (!$limit->isAccepted()) {
            $response = $this->json(['error' => 'Too many requests'], Response::HTTP_TOO_MANY_REQUESTS);

            $retryAfter = $limit->getRetryAfter();
            if ($retryAfter instanceof \DateTimeInterface) {
                $seconds = max(1, $retryAfter->getTimestamp() - time());
                $response->headers->set('Retry-After', (string) $seconds);
            }

            return $this->cors($response);
        }

        return null;
    }

    private function getBearerToken(Request $request): ?string
    {
        $authHeader = $request->headers->get('Authorization');

        if (!$authHeader || !preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
            return null;
        }

        return $matches[1] ?? null;
    }

    private function migrateLegacyClientIfNeeded(ExtensionClient $client, Request $request): ?string
    {
        if ($client->getClientSecretHash()) {
            return null;
        }

        $issuedInstallationToken = $this->extensionClientManager->rotateInstallationToken($client);
        $client->setDeviceLabel($this->cleanNullable($request->headers->get('X-Device-Label')));
        $client->setBrowserName($this->cleanNullable($request->headers->get('X-Browser-Name')));
        $client->setBrowserVersion($this->cleanNullable($request->headers->get('X-Browser-Version')));
        $client->setOsName($this->cleanNullable($request->headers->get('X-OS-Name')));
        $client->setOsVersion($this->cleanNullable($request->headers->get('X-OS-Version')));
        $client->setExtensionVersion($this->cleanNullable($request->headers->get('X-Extension-Version')));
        $client->setManifestVersion($this->cleanNullable($request->headers->get('X-Extension-Manifest-Version')));
        $client->setOriginType($this->cleanNullable($request->headers->get('X-Extension-Origin')));
        $client->setLastIpAddress($request->getClientIp());
        $client->setLastUserAgent($request->headers->get('User-Agent'));
        $client->touch();

        return $issuedInstallationToken;
    }

    private function cleanNullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 1000);
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = preg_replace('#^www\.#', '', $domain);

        return strtolower(trim($domain));
    }

    private function credentialMatchesDomain(Credential $credential, string $domain): bool
    {
        $credentialDomain = $this->normalizeDomain((string) $credential->getDomain());

        return $credentialDomain === $domain || str_ends_with($credentialDomain, '.' . $domain);
    }

    private function decryptWithUserKeys(User $user, string $encrypted): string
    {
        $primaryKey = $user->getCredentialEncryptionKey();
        if (is_string($primaryKey) && $primaryKey !== '') {
            $this->encryptionService->setKeyFromUserSecret($primaryKey);
            $plaintext = $this->encryptionService->decrypt($encrypted);
            if ($plaintext !== '') {
                return $plaintext;
            }
        }

        $legacyKey = $user->getApiExtensionToken();
        if (is_string($legacyKey) && $legacyKey !== '' && $legacyKey !== $primaryKey) {
            $this->encryptionService->setKeyFromUserSecret($legacyKey);

            return $this->encryptionService->decrypt($encrypted);
        }

        return '';
    }

    /**
     * @return array{user: User, client: ExtensionClient, issuedInstallationToken: ?string}|array{rate_limited: JsonResponse}|array{response: JsonResponse}|null
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

        try {
            $clientId = trim((string) $request->headers->get('X-Extension-Client-Id', ''));

            if ($clientId === '') {
                throw new \RuntimeException('Header requis manquant : X-Extension-Client-Id');
            }

            $existingClient = null;
            foreach ($user->getExtensionClients() as $client) {
                if ($client->getClientId() === $clientId) {
                    $existingClient = $client;
                    break;
                }
            }

            $issuedInstallationToken = null;

            if ($existingClient instanceof ExtensionClient && !$existingClient->getClientSecretHash()) {
                $this->extensionClientManager->assertAllowed($existingClient);
                $issuedInstallationToken = $this->migrateLegacyClientIfNeeded($existingClient, $request);
                $client = $existingClient;
            } else {
                $resolved = $this->extensionClientManager->resolveFromRequest($user, $request);

                if ($resolved['status'] === 'approval_required') {
                    return [
                        'response' => $this->cors($this->json([
                            'status' => 'email_verification_required',
                            'message' => $resolved['message'],
                            'challenge' => [
                                'publicId' => $resolved['challenge']->getPublicId(),
                                'expiresAt' => $resolved['challenge']->getExpiresAt()?->format(DATE_ATOM),
                            ],
                        ], Response::HTTP_ACCEPTED)),
                    ];
                }

                $client = $resolved['client'];
                $issuedInstallationToken = $resolved['installationToken'];
                $this->extensionClientManager->assertAllowed($client);
            }
        } catch (\RuntimeException $e) {
            return ['response' => $this->forbidden($e->getMessage())];
        }

        return [
            'user' => $user,
            'client' => $client,
            'issuedInstallationToken' => $issuedInstallationToken,
        ];
    }

    #[Route('/extention/api/search', name: 'api_credential_search', methods: ['POST', 'OPTIONS'])]
    public function apiSearch(Request $request, CredentialRepository $credentialRepository, SharedAccessRepository $sharedAccessRepository, TeamRepository $teamRepository): JsonResponse
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
        if (isset($auth['response'])) {
            return $auth['response'];
        }

        $payload = json_decode($request->getContent(), true);
        $domain = $payload['domain'] ?? null;
        if (!is_string($domain) || trim($domain) === '') {
            return $this->badRequest('Domaine non spécifié');
        }

        $domain = $this->normalizeDomain($domain);

        $user = $auth['user'];
        $credentialsById = [];

        foreach ($credentialRepository->findByDomainAndUser($domain, $user) as $credential) {
            $credentialsById[$credential->getId()] = $credential;
        }

        foreach ($sharedAccessRepository->findSharedWith($user) as $sharedAccess) {
            $credential = $sharedAccess->getCredential();
            if ($credential instanceof Credential && $this->credentialMatchesDomain($credential, $domain)) {
                $credentialsById[$credential->getId()] = $credential;
            }
        }

        foreach ($teamRepository->findTeamWithCredentialsByUser($user) as $team) {
            foreach ($team->getCredentials() as $credential) {
                if ($this->credentialMatchesDomain($credential, $domain)) {
                    $credentialsById[$credential->getId()] = $credential;
                }
            }
        }

        $credentials = array_values($credentialsById);
        $result = array_map(
            static fn(Credential $credential) => [
                'id' => $credential->getId(),
                'domain' => $credential->getDomain(),
                'username' => $credential->getUsername(),
                'name' => $credential->getName(),
            ],
            $credentials
        );

        return $this->withInstallationToken($this->json(['credentials' => $result]), $auth['issuedInstallationToken']);
    }

    #[Route('/extention/api/credentials/list', name: 'api_credential_list', methods: ['GET', 'OPTIONS'])]
    public function apiCredentials(Request $request, CredentialRepository $credentialRepository, SharedAccessRepository $sharedAccessRepo, TeamRepository $teamRepo): JsonResponse
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
        if (isset($auth['response'])) {
            return $auth['response'];
        }

        $user = $auth['user'];
        $credentials = $credentialRepository->findCredentialsByUser($user);
        $sharedAccess = $sharedAccessRepo->findSharedWith($user);
        $teams = $teamRepo->findTeamWithCredentialsByUser($user);

        $userPayload = static fn(User $u) => ['id' => $u->getId(), 'email' => $u->getEmail()];
        $credentialPayload = static fn(Credential $c) => ['id' => $c->getId(), 'domain' => $c->getDomain(), 'username' => $c->getUsername(), 'name' => $c->getName()];

        $resultCredentials = array_map(static fn(Credential $credential) => $credentialPayload($credential), $credentials);
        $resultSharedAccess = array_map(
            static function ($sharedAccess) use ($userPayload, $credentialPayload) {
                return [
                    'id' => $sharedAccess->getId(),
                    'createdAt' => $sharedAccess->getCreatedAt()?->format(DATE_ATOM),
                    'owner' => $userPayload($sharedAccess->getOwner()),
                    'guest' => $userPayload($sharedAccess->getGuest()),
                    'credential' => $credentialPayload($sharedAccess->getCredential()),
                ];
            },
            $sharedAccess
        );

        $resultTeamSharedAccess = array_map(
            static function ($team) use ($userPayload, $credentialPayload) {
                $members = [];
                foreach ($team->getMembers() as $teamMember) {
                    $members[] = $userPayload($teamMember->getUser());
                }

                $teamCredentials = [];
                foreach ($team->getCredentials() as $credential) {
                    $teamCredentials[] = $credentialPayload($credential);
                }

                return [
                    'team' => [
                        'id' => $team->getId(),
                        'name' => $team->getName(),
                        'createdAt' => $team->getCreatedAt()?->format(DATE_ATOM),
                        'owner' => $userPayload($team->getOwner()),
                        'members' => $members,
                    ],
                    'credentials' => $teamCredentials,
                ];
            },
            $teams
        );

        return $this->withInstallationToken($this->json([
            'credentials' => $resultCredentials,
            'sharedAccess' => $resultSharedAccess,
            'teamSharedAccess' => $resultTeamSharedAccess,
        ]), $auth['issuedInstallationToken']);
    }

    #[Route('/extention/api/credentials/{id}/reveal', name: 'api_credential_reveal', methods: ['POST', 'OPTIONS'])]
    public function reveal(Request $request, int $id, CredentialRepository $credentialRepository, SharedAccessRepository $sharedAccessRepo, TeamRepository $teamRepo): JsonResponse
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
        if (isset($auth['response'])) {
            return $auth['response'];
        }

        $cred = $credentialRepository->find($id);
        if (!$cred instanceof Credential) {
            return $this->withInstallationToken($this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND), $auth['issuedInstallationToken']);
        }

        $user = $auth['user'];
        $isOwner = ($cred->getUser()?->getId() === $user->getId());
        $hasDirectShare = $sharedAccessRepo->userHasAccessToCredential($user, $cred);
        $hasTeamShare = $teamRepo->userHasTeamAccessToCredential($user, $cred);

        if (!$isOwner && !$hasDirectShare && !$hasTeamShare) {
            return $this->withInstallationToken($this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND), $auth['issuedInstallationToken']);
        }

        $owner = $cred->getUser();
        if (!$owner) {
            return $this->withInstallationToken($this->json(['error' => 'Owner key missing'], Response::HTTP_CONFLICT), $auth['issuedInstallationToken']);
        }

        $password = $this->decryptWithUserKeys($owner, (string) $cred->getPassword());

        if ($password === '') {
            return $this->withInstallationToken($this->json(['error' => 'Unable to decrypt credential'], Response::HTTP_CONFLICT), $auth['issuedInstallationToken']);
        }

        return $this->withInstallationToken($this->json(['id' => $cred->getId(), 'password' => $password]), $auth['issuedInstallationToken']);
    }

    #[Route('/extention/api/credentials/create', name: 'api_credential_create', methods: ['POST', 'OPTIONS'])]
    public function createCredential(Request $request, CredentialRepository $credentialRepository, EntityManagerInterface $entityManager): JsonResponse
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
        if (isset($auth['response'])) {
            return $auth['response'];
        }

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

        if (!$name || !is_string($name) || trim($name) === '') {
            $name = $domain . ' - ' . $username;
        }

        $existingCredential = $credentialRepository->findOneBy([
            'user' => $auth['user'],
            'domain' => $domain,
            'username' => $username,
        ]);

        if ($existingCredential instanceof Credential) {
            return $this->withInstallationToken($this->json([
                'error' => 'Un credential existe déjà pour ce domaine et cet utilisateur',
                'credentialId' => $existingCredential->getId(),
            ], Response::HTTP_CONFLICT), $auth['issuedInstallationToken']);
        }

        $this->encryptionService->setKeyFromUserSecret($auth['user']->ensureCredentialEncryptionKey());
        $encryptedPassword = $this->encryptionService->encrypt($password);

        $credential = new Credential();
        $credential->setName($name);
        $credential->setDomain($domain);
        $credential->setUsername($username);
        $credential->setPassword($encryptedPassword);
        $credential->setUser($auth['user']);

        $entityManager->persist($credential);
        $entityManager->flush();

        return $this->withInstallationToken($this->json([
            'success' => true,
            'credential' => [
                'id' => $credential->getId(),
                'name' => $credential->getName(),
                'domain' => $credential->getDomain(),
                'username' => $credential->getUsername(),
                'createdAt' => $credential->getCreatedAt()?->format(DATE_ATOM),
            ],
        ], Response::HTTP_CREATED), $auth['issuedInstallationToken']);
    }
}
