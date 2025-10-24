<?php

namespace App\Controller\Api;


use App\Repository\CredentialRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


final class BasicRequestController extends AbstractController
{
    #[Route('/api/credentials/length', name: 'api_credentials_length', methods: ['GET'])]
    public function credentialsLength(CredentialRepository $credentialRepo, Request $request): JsonResponse 
    {
       
        $user = $this->getUser();

         if (!$user) {
            throw $this->createNotFoundException('Utilisateur 404');
        }
       
       $count = $credentialRepo->countByUser($user);
       

       return $this->json(['count' => $count]);
    }
}
