<?php

namespace App\Controller\Front;

use App\Entity\Credential;
use App\Repository\CredentialRepository;
use App\Repository\SharedAccessRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


 final class CredentialPageController extends AbstractController
{
    #[Route('/app/credential', name: 'app_credential')]
    public function index(SharedAccessRepository $sharedAccessRepository ,CredentialRepository $credentialRepository): Response
    {
        $user = $this->getUser();
        $sharedAccesses = $sharedAccessRepository->findSharedWith($this->getUser());
        
        return $this->render('credential/index.html.twig', [
            'credentials' => $credentialRepository->findByUser($user),
            'sahredAccesses' =>  $sharedAccesses = $sharedAccessRepository->findSharedWith($this->getUser()),
            'heading' => 'Mes accès'
        ]);
    }
    #[Route('/app/credential/new', name: 'credential_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $credential = new Credential();
        $form = $this->createForm(CredentialType::class, $credential);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Associer l'identifiant à l'utilisateur connecté
            $credential->setUser($this->getUser());
            
            // Normaliser le domaine
            $this->normalizeDomain($credential);
            
            // Chiffrer le mot de passe
            $plainPassword = $credential->getPassword();
            $encryptedPassword = $this->encryptionService->encrypt($plainPassword);
            $credential->setPassword($encryptedPassword);
            
            $this->entityManager->persist($credential);
            $this->entityManager->flush();

            $this->addFlash('success', 'Nouvel identifiant ajouté avec succès.');
            return $this->redirectToRoute('credential_index');
        }

        return $this->render('credential/new.html.twig', [
            'credential' => $credential,
            'form' => $form,
            'heading' => 'Mes accès'
        ]);
    }
}