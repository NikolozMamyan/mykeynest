<?php
namespace App\Service;

use App\Entity\Character;
use App\Service\RoundService;
use App\Repository\InventoryRepository;

class BattleService
{
    private int $lastAttackDamage;
    private int $lastDefenseValue;

    public function battle(Character $char1, Character $char2): array
    {
        // 1) Déterminer qui attaque en premier en fonction de la vitesse
        $first = ($char1->getSpeed() >= $char2->getSpeed()) ? $char1 : $char2;
        $second = ($first === $char1) ? $char2 : $char1;

        // 2) Tour du premier attaquant
        $result1 = $this->attack($first, $second);

        // 3) Vérifier si le deuxième personnage est KO
        if ($result1['isKo']) {
            return [$result1];
        }

        // 4) Tour du deuxième attaquant
        $result2 = $this->attack($second, $first);

        return [$result1, $result2];
    }

    public function attack(array &$attacker, array &$defender): array
    {
        // ✅ S'assurer que `stamina` est bien défini
        if (!isset($attacker['stamina'])) {
            $attacker['stamina'] = 100;
        }
        if (!isset($defender['stamina'])) {
            $defender['stamina'] = 100;
        }
    
        // 1) Vérifier si le défenseur esquive
        if ($this->evadeAttack($defender)) {
            return [
                'damage' => 0,
                'log'    => sprintf('%s esquive l’attaque de %s !', $defender['name'], $attacker['name']),
                'isKo'   => false,
            ];
        }
    
        // 2) Calcul des dégâts subis
        $damage = $this->calculateDamage($attacker, $defender);
    
        // 3) Appliquer les dégâts
        $defender['hp'] -= $damage;
        $defender['stamina'] = max(0, $defender['stamina'] - 1); // ✅ Évite que ça descende sous 0
    
        // 4) Vérifier que les HP ne descendent pas sous 0
        if ($defender['hp'] < 0) {
            $defender['hp'] = 0;
        }
    
        // 5) Calculer la consommation de stamina en fonction de la force
        $staminaCost = max(1, ceil($attacker['strength'] * 1.2)); // ✅ Empêche une consommation trop faible
        $attacker['stamina'] = max(0, $attacker['stamina'] - $staminaCost);
    
        // 6) Régénération de stamina du défenseur en fonction de sa vitesse
        $regenFactor = max(1, ceil($defender['speed'] / 2)); // ✅ Régénération plus équilibrée
    
        // ✅ Vérifier si la stamina est <= 15 pour booster la régénération une seule fois
        if ($defender['stamina'] <= 15) {
            $regenFactor = ceil($regenFactor * 1.3); // ✅ Boost de 30% correctement appliqué
        }
    
        // Appliquer la régénération
        $defender['stamina'] += $regenFactor;
    
        // Empêcher la stamina de dépasser 100
        if ($defender['stamina'] > 100) {
            $defender['stamina'] = 100;
        }
    
        // 7) Vérifier KO
        $isKo = ($defender['hp'] <= 0);
    
        // 8) Générer un log
        $log = sprintf(
            '%s inflige %d dégâts à %s. %s a maintenant %d de stamina (coût: -%d).',
            $attacker['name'],
            $damage,
            $defender['name'],
            $attacker['name'],
            $attacker['stamina'],
            $staminaCost
        );
    
        if ($isKo) {
            $log .= sprintf(' %s est K.O. !', $defender['name']);
        }
    
        return [
            'damage' => $damage,
            'log'    => $log,
            'isKo'   => $isKo,
        ];
    }
    
    private function calculateDamage(array $attacker, array $defender): int
    {
        // 1) Calcul de la fourchette de dégâts aléatoires
        $minDamage = max(1, $attacker['strength'] - 4);
        $maxDamage = $attacker['strength'];

        // 2) Si la stamina est faible (< 30), la force diminue de 30%
        if ($attacker['stamina'] < 30) {
            $maxDamage = (int) round($maxDamage * 0.7);
        }

        $maxDamage = max($minDamage, $maxDamage);
        $randomDamage = random_int($minDamage, $maxDamage);
        

        // 3) Appliquer le bonus de 9% à l'attaque de base
        $boostedDamage = (int) round($randomDamage * 0.9);
        $this->lastAttackDamage = $boostedDamage; // Stocker pour le log

        // 4) Calcul de la défense aléatoire
        $defenseValue = $this->calculateDefense($defender);
        $this->lastDefenseValue = $defenseValue; // Stocker pour le log

        // 5) Calcul des dégâts de base
        $damage = max(1, $boostedDamage - $defenseValue);

        return $damage;
    }

    private function calculateDefense(array $defender): int
    {
        $minDefense = 0;
        $maxDefense = max(1, $defender['defense']);

        $chance = random_int(1, 100);

        if ($chance <= 60) {
            return random_int($minDefense, (int) floor($maxDefense / 2));
        } else {
            return random_int((int) ceil($maxDefense / 2), $maxDefense);
        }
    }

    private function evadeAttack(array &$defender): bool
    {
        $chanceToEvade = min(50, $defender['agility'] * 2); // Max 50% d’esquive
        $evadeSuccess = random_int(1, 100) <= $chanceToEvade;
    
        if ($evadeSuccess) {
            // Ajouter 4 points de stamina si l'esquive réussit
            $defender['stamina'] += 4;
    
            // S'assurer que la stamina ne dépasse pas 100
            if ($defender['stamina'] > 100) {
                $defender['stamina'] = 100;
            }
        }
    
        return $evadeSuccess;
    }
    public function processRound(array $battleState, RoundService $roundService): array
{
    // Récupérer les stats modifiées
    $char1Stats = $roundService->getCharacterStatsWithPerks($battleState['char1']);
    $char2Stats = $roundService->getCharacterStatsWithPerks($battleState['char2']);

    // Tour du premier attaquant
    $result1 = $this->attack($char1Stats, $char2Stats);
    $battleState['char2']['hp'] -= $result1['damage'];
    $battleState['logs'][] = $result1['log'];

    // Vérifier KO
    if ($result1['isKo']) {
        $battleState['isOver'] = true;
        return $battleState;
    }

    // Tour du deuxième attaquant
    $result2 = $this->attack($char2Stats, $char1Stats);
    if ($result2['isKo']) {
        $battleState['isOver'] = true;
    }

    $battleState['logs'][] = $result1['log'];
    $battleState['logs'][] = $result2['log'];

    return $battleState;
}

    
}