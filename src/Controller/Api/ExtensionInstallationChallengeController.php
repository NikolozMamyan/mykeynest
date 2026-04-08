<?php

namespace App\Controller\Api;

use App\Service\ExtensionInstallationChallengeManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ExtensionInstallationChallengeController extends AbstractController
{
    #[Route('/api/extension-installation-challenge/{token}/approve', name: 'api_extension_installation_challenge_approve', methods: ['GET'])]
    public function approve(
        string $token,
        ExtensionInstallationChallengeManager $challengeManager
    ): Response {
        $challenge = $challengeManager->findValidByPlainToken($token);

        if (!$challenge) {
            return new Response(
                '<h1>Lien invalide ou expiré</h1><p>Cette demande d’installation n’est plus valide.</p>',
                400
            );
        }

        $challengeManager->approve($challenge);

        return new Response(
            '<h1>Installation approuvée</h1><p>Vous pouvez revenir sur votre autre appareil. L’extension sera autorisée automatiquement au prochain essai.</p>'
        );
    }

    #[Route('/api/extension-installation-challenge/{token}/reject', name: 'api_extension_installation_challenge_reject', methods: ['GET'])]
    public function reject(
        string $token,
        ExtensionInstallationChallengeManager $challengeManager
    ): Response {
        $challenge = $challengeManager->findValidByPlainToken($token);

        if (!$challenge) {
            return new Response(
                '<h1>Lien invalide ou expiré</h1><p>Cette demande d’installation n’est plus valide.</p>',
                400
            );
        }

        $challengeManager->reject($challenge);

        return new Response(
            '<h1>Installation refusée</h1><p>Cette nouvelle installation ne sera pas autorisée.</p>'
        );
    }
}
