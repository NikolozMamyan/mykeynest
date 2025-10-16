<?php

namespace App\Controller\Api\Extention;

use App\Entity\SharedAccess;
use App\Repository\UserRepository;
use App\Service\EncryptionService;
use App\Repository\SharedAccessRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class ApiSharedController extends AbstractController
{
#[Route('/api/shared', name: 'api_shared_credentials', methods: ['GET', 'OPTIONS'])]
public function apiShared(
    Request $request,
    SharedAccessRepository $sharedAccessRepository,
    EncryptionService $encryptionService,
    UserRepository $userRepository
): Response {
    // CORS preflight
    if ($request->getMethod() === 'OPTIONS') {
        return new Response('', 204);
    }

    // Auth via token (X-AUTH-TOKEN header)
    $token = $request->headers->get('X-AUTH-TOKEN');
    if (!$token) {
        return $this->json(['error' => 'Token manquant'], Response::HTTP_UNAUTHORIZED);
    }

    $user = $userRepository->findOneBy(['apiToken' => $token]);
    if (!$user) {
        return $this->json(['error' => 'Token invalide'], Response::HTTP_UNAUTHORIZED);
    }

    $sharedAccesses = $sharedAccessRepository->findSharedWith($user);

    $data = array_map(function (SharedAccess $access) use ($encryptionService) {
        $credential = $access->getCredential();
        return [
            'id' => $credential->getId(),
            'domain' => $credential->getDomain(),
            'username' => $credential->getUsername(),
            'password' => $encryptionService->decrypt($credential->getPassword()),
            'name' => $credential->getName(),
            'shared_by' => $access->getOwner()->getEmail(),
        ];
    }, $sharedAccesses);

    return $this->json(['credentials' => $data]);
}

}
