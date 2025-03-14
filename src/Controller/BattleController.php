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

        // Déterminer qui attaque en premier en fonction du speed
        $firstAttacker = ($char1->getSpeed() >= $char2->getSpeed()) ? 'char1' : 'char2';
        $secondDefender = ($firstAttacker === 'char1') ? 'char2' : 'char1';

        $battleState = [
            'char1' => [
                'name'     => $char1->getName(),
                'hp'       => $char1->getHp(),
                'strength' => $char1->getStrength(),
                'defense'  => $char1->getDefense(),
                'speed'    => $char1->getSpeed(),
                'agility'  => $char1->getAgility(),
                'stamina'  => $char1->getStamina(),
            ],
            'char2' => [
                'name'     => $char2->getName(),
                'hp'       => $char2->getHp(),
                'strength' => $char2->getStrength(),
                'defense'  => $char2->getDefense(),
                'speed'    => $char2->getSpeed(),
                'agility'  => $char2->getAgility(),
                'stamina'  => $char2->getStamina(),
            ],
            'logs'   => [],
            'isOver' => false,
            'turn'   => $firstAttacker, // On assigne le premier attaquant en fonction du speed
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

    // 4) Exécuter l’attaque via BattleService en passant des tableaux
    $result = $battleService->attack($battleState[$attackerKey], $battleState[$defenderKey]);

    // 5) Mise à jour des HP dans le tableau de session
    $battleState[$defenderKey]['hp'] -= $result['damage'];

    // 6) Vérifier que les HP ne descendent pas en dessous de 0
    if ($battleState[$defenderKey]['hp'] < 0) {
        $battleState[$defenderKey]['hp'] = 0;
    }

    // 7) Enregistrement du log
    $battleState['logs'][] = $result['log'];

    // 8) Vérification KO
    if ($battleState[$defenderKey]['hp'] <= 0) {
        $battleState['isOver'] = true;
    } else {
        // 9) Passer le tour au prochain
        $battleState['turn'] = $defenderKey;
    }

    // 10) Sauvegarde de l’état en session
    $session->set('battle_state', $battleState);

    // 11) On renvoie l'état mis à jour
    return new JsonResponse([
        'battleState' => $battleState,
        'damage'      => $result['damage'],
        'lastAttacker' => $attackerKey
    ]);
}

    
}
