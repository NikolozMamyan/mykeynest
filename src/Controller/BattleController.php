<?php

namespace App\Controller;

use App\Service\BattleService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route('/battle')]
final class BattleController extends AbstractController
{
    #[Route('/init', name: 'battle_init')]
    public function init(SessionInterface $session): Response
    {
        // On initialise les caractéristiques des personnages en session
        $battleState = [
            'char1' => [
                'name'     => 'Boxeur',
                'hp'       => 100,
                'strength' => 6,  
                'defense'  => 5,
            ],
            'char2' => [
                'name'     => 'Karateka',
                'hp'       => 100,
                'strength' => 7,  
                'defense'  => 3,
            ],
            'logs'   => [],
            'isOver' => false,
            'turn'   => 'char1', // Premier attaquant
        ];

        // On stocke l'état du combat en session
        $session->set('battle_state', $battleState);

        return $this->render('battle/fight.html.twig', [
            'battleState' => $battleState,
        ]);
    }

    #[Route('/tick', name: 'battle_tick')]
    public function tick(SessionInterface $session, BattleService $battleService): JsonResponse
    {
        // 1) Récupération du state depuis la session
        $battleState = $session->get('battle_state');

        // 2) Vérification
        if (!$battleState) {
            return new JsonResponse(['error' => 'No battle in progress'], 400);
        }
        if ($battleState['isOver']) {
            return new JsonResponse($battleState);
        }

        // 3) Détermination de l'attaquant et du défenseur
        $attackerKey = $battleState['turn'];
        $defenderKey = ($attackerKey === 'char1') ? 'char2' : 'char1';

        // 4) Exécuter l’attaque via BattleService
        $result = $battleService->attack($battleState[$attackerKey], $battleState[$defenderKey]);

        // 5) Enregistrement du log
        $battleState['logs'][] = $result['log'];

        // 6) Vérification KO
        if ($result['isKo']) {
            $battleState['isOver'] = true;
        } else {
            // 7) Passer le tour au prochain
            $battleState['turn'] = $defenderKey;
        }

        // 8) Sauvegarde de l’état en session
        $session->set('battle_state', $battleState);

        // 9) On renvoie l'état mis à jour
        return new JsonResponse([
            'battleState' => $battleState,
            'damage'      => $result['damage'], // Ajout des dégâts infligés
            'lastAttacker' => $attackerKey
        ]);
    }
}
