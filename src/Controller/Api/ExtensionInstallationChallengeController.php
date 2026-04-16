<?php

namespace App\Controller\Api;

use App\Service\ExtensionInstallationChallengeManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ExtensionInstallationChallengeController extends AbstractController
{
    #[Route('/api/extension-installation-challenge/{token}/approve', name: 'api_extension_installation_challenge_approve', methods: ['GET', 'POST'])]
    public function approve(
        Request $request,
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

        if ($request->isMethod(Request::METHOD_GET)) {
            return $this->render('security/challenge_action_confirm.html.twig', [
                'title' => 'Autoriser l’extension',
                'message' => 'Confirmez-vous l’autorisation de cette nouvelle installation d’extension ?',
                'confirmLabel' => 'Oui, autoriser',
                'cancelLabel' => 'Annuler',
                'csrfTokenId' => 'extension_installation_approve_' . $token,
                'autoSubmit' => true,
            ]);
        }

        if (!$this->isCsrfTokenValid('extension_installation_approve_' . $token, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF token invalide.');
        }

        $challengeManager->approve($challenge);

        return new Response(
            '<h1>Installation approuvée</h1><p>Vous pouvez revenir sur votre autre appareil. L’extension sera autorisée automatiquement au prochain essai.</p>'
        );
    }

    #[Route('/api/extension-installation-challenge/{token}/reject', name: 'api_extension_installation_challenge_reject', methods: ['GET', 'POST'])]
    public function reject(
        Request $request,
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

        if ($request->isMethod(Request::METHOD_GET)) {
            return $this->render('security/challenge_action_confirm.html.twig', [
                'title' => 'Refuser l’extension',
                'message' => 'Confirmez-vous le refus de cette nouvelle installation d’extension ?',
                'confirmLabel' => 'Oui, refuser',
                'cancelLabel' => 'Annuler',
                'csrfTokenId' => 'extension_installation_reject_' . $token,
                'autoSubmit' => true,
            ]);
        }

        if (!$this->isCsrfTokenValid('extension_installation_reject_' . $token, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF token invalide.');
        }

        $challengeManager->reject($challenge);

        return new Response(
            '<h1>Installation refusée</h1><p>Cette nouvelle installation ne sera pas autorisée.</p>'
        );
    }
}
