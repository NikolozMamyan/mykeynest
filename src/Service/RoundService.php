<?php
namespace App\Service;

use App\Repository\CharacterRepository;
use App\Repository\InventoryRepository;

class RoundService
{
    private $battleService;
    private CharacterRepository $characterRepository;
    private InventoryRepository $inventoryRepository;
    
    public function __construct(BattleService $battleService, CharacterRepository $characterRepository, InventoryRepository $inventoryRepository)
    {
        $this->battleService = $battleService;
        $this->characterRepository = $characterRepository;
        $this->inventoryRepository = $inventoryRepository;
    }
    
    public function initRound(array $battleState): array
    {
        // Initialiser le round s'il n'existe pas
        if (!isset($battleState['round'])) {
            $battleState['round'] = [
                'current' => 1,
                'status' => 'active',
                'remainingTicks' => 5, // 15 ticks par round
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
        if (!isset($characterData['key'])) {
            return ['error' => 'Character data is invalid'];
        }
    
        $character = $this->characterRepository->findOneBy(['name' => $characterData['name']]);
    
        if (!$character) {
            return ['error' => 'Character not found'];
        }
    
        $inventory = $this->inventoryRepository->findOneBy(['character' => $character]);
    
        if (!$inventory) {
            return ['perks' => [], 'slots' => []];
        }
    
        // 🔹 Récupérer les perks
        $perks = $inventory->getPerks()->map(function ($perk) {
            return [
                'id' => $perk->getId(),
                'name' => $perk->getName(),
                'value' => $perk->getValue(),
                'type' => $perk->getType(),
            ];
        })->toArray();
    
        // 🔹 Appliquer les effets des perks aux stats du personnage
        $modifiedStats = [
            'hp' => $character->getHp(),
            'strength' => $character->getStrength(),
            'defense' => $character->getDefense(),
            'speed' => $character->getSpeed(),
            'agility' => $character->getAgility(),
            'stamina' => $character->getStamina(),
        ];
    
        foreach ($perks as $perk) {
            if (isset($modifiedStats[$perk['type']])) {
                $modifiedStats[$perk['type']] += $perk['value'];
            }
        }
    
        // 🔹 Générer les slots (Exemple: Tête, Corps, Jambes)
        $slots = [
            ['id' => 1, 'name' => 'Tête', 'perkId' => null],
            ['id' => 2, 'name' => 'Corps', 'perkId' => null],
            ['id' => 3, 'name' => 'Jambes', 'perkId' => null]
        ];
    
        return [
            'perks' => $perks,
            'slots' => $slots, // ✅ Slots ajoutés
            'modifiedStats' => $modifiedStats // ✅ Stats mises à jour avec les perks
        ];
    }
    

    

    
    public function equipPerk(array $battleState, string $characterKey, int $slotId, int $perkId): array
    {
        $characterName = $battleState[$characterKey]['name'];
        $character = $this->characterRepository->findOneBy(['name' => $characterName]);
    
        if (!$character) {
            $battleState['logs'][] = "Erreur : Personnage introuvable.";
            return $battleState;
        }
    
        $inventory = $this->inventoryRepository->findOneBy(['character' => $character]);
    
        if (!$inventory) {
            $battleState['logs'][] = "Erreur : Inventaire introuvable pour " . $characterName;
            return $battleState;
        }
    
        // Vérifier si le perk existe dans l'inventaire
        $perkToEquip = null;
        foreach ($inventory->getPerks() as $perk) {
            if ($perk->getId() === $perkId) {
                $perkToEquip = $perk;
                break;
            }
        }
    
        if (!$perkToEquip) {
            $battleState['logs'][] = "Erreur : Perk introuvable dans l'inventaire de " . $characterName;
            return $battleState;
        }
    
        // Vérifier et récupérer les slots actuels
        if (!isset($battleState[$characterKey]['slots'])) {
            $battleState[$characterKey]['slots'] = [
                ['id' => 1, 'name' => 'Tête', 'perkId' => null],
                ['id' => 2, 'name' => 'Corps', 'perkId' => null],
                ['id' => 3, 'name' => 'Jambes', 'perkId' => null]
            ];
        }
    
        $slotFound = false;
        foreach ($battleState[$characterKey]['slots'] as &$slot) {
            if ($slot['id'] === $slotId) {
                $slotFound = true;
    
                if ($slot['perkId'] !== null) {
                    $battleState['logs'][] = "Erreur : Le slot " . $slot['name'] . " est déjà équipé.";
                    return $battleState;
                }
    
                // Affecter le perk au slot
                $slot['perkId'] = $perkId;
    
                // Appliquer immédiatement le bonus du perk sur les stats du personnage
                $perkType = $perkToEquip->getType();
                $perkValue = $perkToEquip->getValue();
    
                if (!isset($battleState[$characterKey][$perkType])) {
                    $battleState['logs'][] = "Erreur : Type de perk invalide.";
                    return $battleState;
                }
    
                $battleState[$characterKey][$perkType] += $perkValue;
    
                // Ajouter un log clair et précis
                $battleState['logs'][] = sprintf(
                    "%s équipe le perk '%s' sur le slot '%s' (+%d %s).",
                    $characterName,
                    $perkToEquip->getName(),
                    $slot['name'],
                    $perkValue,
                    $perkType
                );
    
                break;
            }
        }
    
        if (!$slotFound) {
            $battleState['logs'][] = "Erreur : Slot introuvable.";
        }
    
        return $battleState;
    }
    
    // méthode qui récupère les stats après application des perks
public function getCharacterStatsWithPerks(array $characterData): array
{
    $character = $this->characterRepository->findOneBy(['name' => $characterData['name']]);

    if (!$character) {
        return $characterData; // Si perso introuvable, retourne les stats originales
    }

    $inventory = $this->inventoryRepository->findOneBy(['character' => $character]);

    $stats = [
        'hp' => $character->getHp(),
        'strength' => $character->getStrength(),
        'defense' => $character->getDefense(),
        'speed' => $character->getSpeed(),
        'agility' => $character->getAgility(),
        'stamina' => $character->getStamina(),
    ];

    if ($inventory) {
        foreach ($inventory->getPerks() as $perk) {
            $type = $perk->getType();
            $value = $perk->getValue();
            // Incrémenter la stat concernée par le perk
            if (array_key_exists($type = $perk->getType(), $stats)) {
                $stats[$type] += $perk->getValue();
            }
        }
    }

    return $stats;
}

}