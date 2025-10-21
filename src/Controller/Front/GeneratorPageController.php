<?php

namespace App\Controller\Front;

use App\Entity\Credential;
use App\Entity\DraftPassword;
use App\Service\CredentialManager;
use App\Service\DraftPasswordManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class GeneratorPageController extends AbstractController
{
    public function __construct(
        private CredentialManager $credentialManager,
        private DraftPasswordManager $draftManager
    ) {}

    #[Route('/app/generator', name: 'app_generator')]
    public function index(EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $drafts = $em->getRepository(DraftPassword::class)->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC'],
            10
        );

        return $this->render('generateur/index.html.twig', [
            'drafts' => $drafts,
        ]);
    }

    #[Route('/app/generator/save-draft', name: 'app_generator_save_draft', methods: ['POST'])]
    public function saveDraft(Request $request): Response
    {
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);
        if (!$user) return $this->json(['error' => 'Non authentifié'], 401);

        $password = $data['password'] ?? null;
        $name = $data['name'] ?? null;
        if (!$password || !$name)
            return $this->json(['error' => 'Nom ou mot de passe manquant'], 400);

        $this->draftManager->create($password, $name, $user);

        return $this->json(['success' => true]);
    }

    #[Route('/app/generator/list-drafts', name: 'app_generator_list_drafts', methods: ['GET'])]
    public function listDrafts(EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $drafts = $em->getRepository(DraftPassword::class)->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC'],
            10
        );

        $draftsData = [];
        foreach ($drafts as $d) {
            $draftsData[] = [
                'id' => $d->getId(),
                'name' => $d->getName(),
                'password' => $this->draftManager->decryptPassword($d), // déchiffrement sécurisé
            ];
        }

        return $this->json(['drafts' => $draftsData]);
    }

    #[Route('/app/generator/convert-draft', name: 'app_generator_convert_draft', methods: ['POST'])]
    public function convertDraft(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);

        $draft = $em->getRepository(DraftPassword::class)->find($data['draftId'] ?? 0);
        if (!$draft || $draft->getUser() !== $user)
            return $this->json(['error' => 'Brouillon introuvable'], 404);

        // Déchiffre le mot de passe avant conversion
        $plainPassword = $this->draftManager->decryptPassword($draft);

        $credential = new Credential();
        $credential->setName($data['name']);
        $credential->setDomain($data['domain']);
        $credential->setUsername($data['username']);
        $credential->setPassword($plainPassword);

        // Création et chiffrement via CredentialManager
        $this->credentialManager->create($credential, $user);

        // Suppression du draft
        $this->draftManager->delete($draft);

        return $this->json(['success' => true]);
    }
}
