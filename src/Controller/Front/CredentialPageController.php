<?php

namespace App\Controller\Front;

use App\Entity\Credential;
use App\Form\CredentialType;
use App\Service\CredentialManager;
use App\Repository\CredentialRepository;
use App\Repository\SharedAccessRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class CredentialPageController extends AbstractController
{
    public function __construct(
        private CredentialManager $credentialManager,
    ) {}

    #[Route('/app/credential', name: 'app_credential')]
    public function index(
        SharedAccessRepository $sharedAccessRepository,
        CredentialRepository $credentialRepository
    ): Response {
        $user = $this->getUser();

        return $this->render('credential/index.html.twig', [
            'credentials' => $credentialRepository->findByUser($user),
            'sharedAccesses' => $sharedAccessRepository->findSharedWith($user),
            'heading' => 'Mes accès',
        ]);
    }

    #[Route('/app/credential/new', name: 'credential_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $credential = new Credential();
        $form = $this->createForm(CredentialType::class, $credential);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->credentialManager->create($credential, $this->getUser());
            $this->addFlash('success', 'Nouvel identifiant ajouté avec succès.');

            return $this->redirectToRoute('app_credential');
        }

        return $this->render('credential/new.html.twig', [
            'form' => $form,
            'heading' => 'Mes accès',
        ]);
    }

    #[Route('/app/credential/{id}', name: 'credential_show', methods: ['GET'])]
    public function show(Credential $credential): Response
    {
        $decryptedPassword = $this->credentialManager->decryptPassword($credential);

        return $this->render('credential/show.html.twig', [
            'credential' => $credential,
            'decryptedPassword' => $decryptedPassword,
            'heading' => 'Mes accès',
        ]);
    }

    #[Route('/app/credential/{id}/edit', name: 'credential_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Credential $credential): Response
    {
        $originalEncryptedPassword = $credential->getPassword();
        $decryptedPassword = $this->credentialManager->decryptPassword($credential);
        $credential->setPassword($decryptedPassword);

        $form = $this->createForm(CredentialType::class, $credential);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->credentialManager->update($credential, $decryptedPassword, $originalEncryptedPassword);
            $this->addFlash('success', 'Identifiant mis à jour avec succès.');

            return $this->redirectToRoute('app_credential');
        }

        return $this->render('credential/edit.html.twig', [
            'form' => $form,
            'heading' => 'Mes accès',
        ]);
    }

    #[Route('/app/credential/{id}', name: 'credential_delete', methods: ['POST'])]
    public function delete(Request $request, Credential $credential): Response
    {
        if ($this->isCsrfTokenValid('delete'.$credential->getId(), $request->request->get('_token'))) {
            $this->credentialManager->delete($credential);
            $this->addFlash('success', 'Identifiant supprimé avec succès.');
        }

        return $this->redirectToRoute('app_credential');
    }
}
