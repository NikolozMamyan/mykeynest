<?php

namespace App\Controller;

use App\Entity\Character;
use App\Service\RoundService;
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
                'key' => 'char1',
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
    public function tick(RequestStack $requestStack, BattleService $battleService, RoundService $roundService): JsonResponse
    {
        // Ajouter un délai de 1.5 seconde
        usleep(1500000);
        
        $session = $requestStack->getSession();
        $battleState = $session->get('battle_state');
        
        // Vérifications
        if (!$battleState) {
            return new JsonResponse(['error' => 'No battle in progress'], 400);
        }
        if ($battleState['isOver']) {
            return new JsonResponse($battleState);
        }
        
        // Vérifier si un round est en cours
        if (!isset($battleState['round'])) {
            // Démarrer un round si nécessaire
            $battleState = $roundService->initRound($battleState);
            $session->set('battle_state', $battleState);
        }
        
        // Si le round est en pause, ne rien faire
        if (isset($battleState['round']) && $battleState['round']['status'] === 'pause') {
            return new JsonResponse([
                'battleState' => $battleState,
                'pauseForInventory' => true
            ]);
        }
        
        // Traiter un tick de round
        $battleState = $roundService->processTick($battleState);
        
        // Si le round vient de se terminer, mettre la bataille en pause
        if ($battleState['round']['status'] === 'pause') {
            $session->set('battle_state', $battleState);
            return new JsonResponse([
                'battleState' => $battleState,
                'pauseForInventory' => true
            ]);
        }
        
        // Continuer le combat normalement
        $attackerKey = $battleState['turn'];
        $defenderKey = ($attackerKey === 'char1') ? 'char2' : 'char1';
        
        // Exécuter l'attaque
        $result = $battleService->attack($battleState[$attackerKey], $battleState[$defenderKey]);
        
        // Mise à jour des HP
        $battleState[$defenderKey]['hp'] -= $result['damage'];
        
        // Vérifier que les HP ne descendent pas en dessous de 0
        if ($battleState[$defenderKey]['hp'] < 0) {
            $battleState[$defenderKey]['hp'] = 0;
        }
        
        // Enregistrement du log
        $battleState['logs'][] = $result['log'];
        
        // Vérification KO
        if ($battleState[$defenderKey]['hp'] <= 0) {
            $battleState['isOver'] = true;
        } else {
            // Passer le tour au prochain
            $battleState['turn'] = $defenderKey;
        }
        
        // Sauvegarde de l'état
        $session->set('battle_state', $battleState);
        
        return new JsonResponse([
            'battleState' => $battleState,
            'damage' => $result['damage'],
            'lastAttacker' => $attackerKey  // C'est bien l'attaquant actuel
        ]);
    }
#[Route('/start-round', name: 'battle_start_round')]
public function startRound(RequestStack $requestStack, RoundService $roundService): JsonResponse
{
    $session = $requestStack->getSession();
    $battleState = $session->get('battle_state');
    
    if (!$battleState) {
        return new JsonResponse(['error' => 'No battle in progress'], 400);
    }
    
    $battleState = $roundService->initRound($battleState);
    $session->set('battle_state', $battleState);
    
    return new JsonResponse($battleState);
}

#[Route('/process-tick', name: 'battle_process_tick')]
public function processTick(RoundService $roundService): JsonResponse
{
    $battleState = $roundService->processTick();
    
    return new JsonResponse($battleState);
}

#[Route('/ready/{characterKey}', name: 'battle_ready')]
public function setReady(string $characterKey, RequestStack $requestStack, RoundService $roundService): JsonResponse
{
    $session = $requestStack->getSession();
    $battleState = $session->get('battle_state');

    if (!$battleState) {
        return new JsonResponse(['error' => 'No battle in progress'], 400);
    }

    // ✅ Marquer `char1` comme "prêt"
    $battleState['round']['readyStatus'][$characterKey] = true;

    // ✅ Si `char1` est prêt, on redémarre immédiatement le combat
    if ($characterKey === 'char1') {
        $battleState['round']['status'] = 'active';
    }

    // ✅ Sauvegarder l'état mis à jour
    $session->set('battle_state', $battleState);

    return new JsonResponse(['battleState' => $battleState]);
}

#[Route('/inventory/{characterKey}', name: 'battle_inventory')]
public function openInventory(string $characterKey, RequestStack $requestStack, RoundService $roundService): JsonResponse
{
    $session = $requestStack->getSession();
    $battleState = $session->get('battle_state');
    
    if (!$battleState) {
        return new JsonResponse(['error' => 'No battle in progress'], 400);
    }
    
    // Vérifier si le round est en pause
    if (!isset($battleState['round']) || $battleState['round']['status'] !== 'pause') {
        return new JsonResponse([
            'error' => 'Cannot open inventory during active round',
            'battleState' => $battleState
        ], 400);
    }
    
    $inventory = $roundService->getInventory($battleState[$characterKey]);
    
    return new JsonResponse([
        'battleState' => $battleState,
        'inventory' => $inventory
    ]);
}

#[Route('/equip-perk/{characterKey}/{slotId}/{perkId}', name: 'battle_equip_perk')]
public function equipPerk(
    string $characterKey, 
    int $slotId, 
    int $perkId, 
    RequestStack $requestStack, 
    RoundService $roundService
): JsonResponse
{
    $session = $requestStack->getSession();
    $battleState = $session->get('battle_state');
    
    if (!$battleState) {
        return new JsonResponse(['error' => 'No battle in progress'], 400);
    }
    
    // Vérifier si le round est en pause
    if (!isset($battleState['round']) || $battleState['round']['status'] !== 'pause') {
        return new JsonResponse([
            'error' => 'Cannot equip perks during active round',
            'battleState' => $battleState
        ], 400);
    }
    
    $battleState = $roundService->equipPerk($battleState, $characterKey, $slotId, $perkId);
    $session->set('battle_state', $battleState);
    
    return new JsonResponse($battleState);
}
    
}
