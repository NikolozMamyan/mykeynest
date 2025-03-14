<?php
namespace App\Service;

class RoundService
{
    private $battleService;
    
    public function __construct(BattleService $battleService)
    {
        $this->battleService = $battleService;
    }
    
    public function initRound(array $battleState): array
    {
        // Initialiser le round s'il n'existe pas
        if (!isset($battleState['round'])) {
            $battleState['round'] = [
                'current' => 1,
                'status' => 'active',
                'remainingTicks' => 15, // 15 ticks par round
                'readyStatus' => [
                    'char1' => false,
                    'char2' => false
                ]
            ];
        } else {
            // Passer au round suivant
            $battleState['round']['current']++;
            $battleState['round']['status'] = 'active';
            $battleState['round']['remainingTicks'] = 15;
            $battleState['round']['readyStatus'] = [
                'char1' => false,
                'char2' => false
            ];
        }
        
        // Ajouter un message au log
        $battleState['logs'][] = "Round " . $battleState['round']['current'] . " commence!";
        
        return $battleState;
    }
    
    public function processTick(array $battleState): array
    {
        if (!isset($battleState['round'])) {
            return $battleState;
        }
    
        // Vérifier si le round est actif
        if ($battleState['round']['status'] !== 'active') {
            return $battleState;
        }
    
        // ✅ Assurer que remainingTicks ne devient pas négatif
        if (!isset($battleState['round']['remainingTicks']) || $battleState['round']['remainingTicks'] <= 0) {
            $battleState['round']['remainingTicks'] = 15; // ✅ Fixer un nombre par défaut
        }
    
        // Décrémenter le nombre de ticks restants
        $battleState['round']['remainingTicks']--;
    
        // Vérifier si le round est terminé
        if ($battleState['round']['remainingTicks'] <= 0) {
            $battleState['round']['status'] = 'pause';
            $battleState['logs'][] = "Fin du round " . $battleState['round']['current'] . ". Pause pour équipement!";
        }
    
        return $battleState;
    }
    
    
    public function setReady(array $battleState, string $characterKey): array
    {
        if (!isset($battleState['round'])) {
            return $battleState;
        }
        
        // Vérifier si le round est en pause
        if ($battleState['round']['status'] !== 'pause') {
            return $battleState;
        }
        
        // Marquer le personnage comme prêt
        $battleState['round']['readyStatus'][$characterKey] = true;
        
        // Vérifier si tous les personnages sont prêts
        $allReady = true;
        foreach ($battleState['round']['readyStatus'] as $status) {
            if (!$status) {
                $allReady = false;
                break;
            }
        }
        
        if ($allReady) {
            // Tout le monde est prêt, initialiser un nouveau round
            return $this->initRound($battleState);
        }
        
        return $battleState;
    }
    
    public function getInventory(array $characterData): array
    {
        // Ici, tu pourrais implémenter la logique pour récupérer l'inventaire réel du personnage
        // Pour l'exemple, renvoie un inventaire factice
        return [
            'perks' => [
                ['id' => 1, 'name' => 'Force accrue', 'effect' => '+5 force'],
                ['id' => 2, 'name' => 'Agilité améliorée', 'effect' => '+3 agilité'],
                ['id' => 3, 'name' => 'Endurance renforcée', 'effect' => '+10 stamina']
            ],
            'slots' => [
                ['id' => 1, 'name' => 'Tête', 'perkId' => null],
                ['id' => 2, 'name' => 'Corps', 'perkId' => null],
                ['id' => 3, 'name' => 'Jambes', 'perkId' => null]
            ]
        ];
    }
    
    public function equipPerk(array $battleState, string $characterKey, int $slotId, int $perkId): array
    {
        // Ici, tu pourrais implémenter la logique pour équiper un perk
        // Pour l'exemple, ajoute un bonus aléatoire
        $stat = ['strength', 'defense', 'speed', 'agility', 'stamina'][rand(0, 4)];
        $bonus = rand(1, 5);
        
        $battleState[$characterKey][$stat] += $bonus;
        
        $battleState['logs'][] = $battleState[$characterKey]['name'] . " équipe un perk donnant +" . $bonus . " " . $stat;
        
        return $battleState;
    }
}