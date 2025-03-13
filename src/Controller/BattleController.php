<?php

namespace App\Controller;

use App\Entity\Character;
use App\Service\BattleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route('/battle')]
final class BattleController extends AbstractController
{
    #[Route('/init', name: 'battle_init')]
    public function init(EntityManagerInterface $entityManager, SessionInterface $session , RequestStack $requestStack): Response
    {
        $char1 = $entityManager->getRepository(Character::class)->find(13);
        $char2 = $entityManager->getRepository(Character::class)->find(15);

        if (!$char1 || !$char2) {
            throw $this->createNotFoundException('Personnage(s) introuvable(s).');
        }
        $battleState = [
            'char1' => [
                'name'     => $char1->getName(),
                'hp'       => $char1->getHp(),
                'strength' => $char1->getStrength(),
                'defense'  => $char1->getDefense(),
            ],
            'char2' => [
                'name'     => $char2->getName(),
                'hp'       => $char2->getHp(),
                'strength' => $char2->getStrength(),
                'defense'  => $char2->getDefense(),
            ],
            'logs'   => [],
            'isOver' => false,
            'turn'   => 'char1', // Premier attaquant
        ];

        // Stockage de l'état du combat en session
        $session = $requestStack->getSession();
        $session->set('battle_state', $battleState);

        return $this->render('battle/fight.html.twig', [
            'battleState' => $battleState,
        ]);
    }

    #[Route('/tick', name: 'battle_tick')]
    public function tick(SessionInterface $session, BattleService $battleService): JsonResponse
    {
        // Ajout d'un délai de 1.5 seconde
        usleep(1500000); 
    
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
