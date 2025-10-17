<?php

namespace App\Controller\Front;

use App\Entity\Credential;
use App\Form\CredentialType;
use App\Service\EncryptionService;
use App\Repository\CredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\SharedAccessRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


 final class CredentialPageController extends AbstractController
{

        public function __construct(
        private EncryptionService $encryptionService,
        private EntityManagerInterface $entityManager
    ) {
    }
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
    public function new(
        Request $request,
        EntityManagerInterface $em,
        EncryptionService $encryptionService
    ): Response {
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
            return $this->redirectToRoute('app_credential');
        }

        return $this->render('credential/new.html.twig', [
            'credential' => $credential,
            'form' => $form,
            'heading' => 'Mes accès'
        ]);
    }

    #[Route('/{id}', name: 'credential_show', methods: ['GET'])]
    public function show(Credential $credential): Response
    {
        // Déchiffrer le mot de passe pour l'affichage
        $decryptedPassword = $this->encryptionService->decrypt($credential->getPassword());
        
        return $this->render('credential/show.html.twig', [
            'credential' => $credential,
            'decryptedPassword' => $decryptedPassword,
            'heading' => 'Mes accès'
        ]);
    }

private function normalizeDomain(Credential $credential): void
{
    $domain = $credential->getDomain();

    // Supprime http:// ou https://
    $domain = preg_replace('#^https?://#', '', $domain);

    // Supprime www.
    $domain = preg_replace('#^www\.#', '', $domain);

    // Supprime le / final s'il existe
    $domain = rtrim($domain, '/');

    $credential->setDomain($domain);
}


     #[Route('/{id}/edit', name: 'credential_edit', methods: ['GET', 'POST'])]
    
    public function edit(Request $request, Credential $credential): Response
    {
        // Déchiffrer temporairement le mot de passe pour l'édition
        $originalEncryptedPassword = $credential->getPassword();
        $decryptedPassword = $this->encryptionService->decrypt($originalEncryptedPassword);
        $credential->setPassword($decryptedPassword);
        
        $form = $this->createForm(CredentialType::class, $credential);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Normaliser le domaine
            $this->normalizeDomain($credential);
            
            // Gérer le mot de passe
            $plainPassword = $credential->getPassword();
            if ($plainPassword !== $decryptedPassword) {
                $encryptedPassword = $this->encryptionService->encrypt($plainPassword);
                $credential->setPassword($encryptedPassword);
            } else {
                $credential->setPassword($originalEncryptedPassword);
            }
            
            $credential->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', 'Identifiant mis à jour avec succès.');
            return $this->redirectToRoute('credential_index');
        }

        return $this->render('credential/edit.html.twig', [
            'credential' => $credential,
            'form' => $form,
            'heading' => 'Mes accès'
        ]);
    }

      #[Route('/{id}', name: 'credential_delete', methods: ['POST'])]
    public function delete(Request $request, Credential $credential): Response
    {
        if ($this->isCsrfTokenValid('delete'.$credential->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($credential);
            $this->entityManager->flush();
            $this->addFlash('success', 'Identifiant supprimé avec succès.');
        }

        return $this->redirectToRoute('credential_index');
    }



}