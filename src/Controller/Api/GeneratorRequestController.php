<?php

namespace App\Controller\Api;

use App\Entity\Credential;
use App\Entity\DraftPassword;
use App\Entity\User;
use App\Service\CredentialManager;
use App\Service\DraftPasswordManager;
use App\Service\SubscriptionPlanService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GeneratorRequestController extends AbstractController
{
    public function __construct(
        private readonly CredentialManager $credentialManager,
        private readonly DraftPasswordManager $draftManager,
        private readonly SubscriptionPlanService $subscriptionPlans,
    ) {
    }

    #[Route('/api/generator/save-draft', name: 'app_generator_save_draft', methods: ['POST'])]
    public function saveDraft(Request $request): Response
    {
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }
        if (!$this->subscriptionPlans->hasFeature($user, SubscriptionPlanService::FEATURE_PASSWORD_GENERATOR)) {
            return $this->json(['error' => 'Fonctionnalité indisponible avec votre plan'], 403);
        }
        if (!is_array($data)) {
            return $this->json(['error' => 'Corps JSON invalide'], 400);
        }

        $password = $data['password'] ?? null;
        $name = $data['name'] ?? null;
        if (!is_string($password) || $password === '' || !is_string($name) || trim($name) === '') {
            return $this->json(['error' => 'Nom ou mot de passe manquant'], 400);
        }

        $this->draftManager->create($password, $name, $user);

        return $this->json(['success' => true]);
    }

    #[Route('/api/generator/list-drafts', name: 'app_generator_list_drafts', methods: ['GET'])]
    public function listDrafts(EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }
        if (!$this->subscriptionPlans->hasFeature($user, SubscriptionPlanService::FEATURE_PASSWORD_GENERATOR)) {
            return $this->json(['error' => 'Fonctionnalité indisponible avec votre plan'], 403);
        }

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

    #[Route('/api/generator/convert-draft', name: 'app_generator_convert_draft', methods: ['POST'])]
    public function convertDraft(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }
        if (!$this->subscriptionPlans->hasFeature($user, SubscriptionPlanService::FEATURE_PASSWORD_GENERATOR)) {
            return $this->json(['error' => 'Fonctionnalité indisponible avec votre plan'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Corps JSON invalide'], 400);
        }

        $draft = $em->getRepository(DraftPassword::class)->find($data['draftId'] ?? 0);
        if (!$draft || $draft->getUser() !== $user) {
            return $this->json(['error' => 'Brouillon introuvable'], 404);
        }

        $name = $data['name'] ?? null;
        $domain = $data['domain'] ?? null;
        $username = $data['username'] ?? null;
        if (
            !is_string($name) || trim($name) === ''
            || !is_string($domain) || trim($domain) === ''
            || !is_string($username) || trim($username) === ''
        ) {
            return $this->json(['error' => 'Informations de l’identifiant invalides'], 400);
        }

        $credentialLimit = $this->subscriptionPlans->getLimit($user, SubscriptionPlanService::LIMIT_CREDENTIALS);
        $credentialCount = $em->getRepository(Credential::class)->count(['user' => $user]);
        if ($credentialLimit !== null && $credentialCount >= $credentialLimit) {
            return $this->json(['error' => sprintf('Limite de %d identifiants atteinte', $credentialLimit)], 409);
        }

        // Déchiffre le mot de passe avant conversion
        $plainPassword = $this->draftManager->decryptPassword($draft);

        $credential = new Credential();
        $credential->setName(trim($name));
        $credential->setDomain(trim($domain));
        $credential->setUsername(trim($username));
        $credential->setPassword($plainPassword);

        // Création et chiffrement via CredentialManager
        $this->credentialManager->create($credential, $user);

        // Suppression du draft
        $this->draftManager->delete($draft);

        return $this->json(['success' => true]);
    }
}
