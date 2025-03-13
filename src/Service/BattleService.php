<?php
namespace App\Service;

use App\Entity\Character;
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

    // 4) Vérifier que les HP ne descendent pas sous 0
    if ($defender['hp'] < 0) {
        $defender['hp'] = 0;
    }

    // 5) Calculer la consommation de stamina en fonction de la force
    $staminaCost = ceil($attacker['strength'] * 1.5);
    $attacker['stamina'] -= $staminaCost;

    // 6) S'assurer que la stamina ne soit pas négative
    if ($attacker['stamina'] < 0) {
        $attacker['stamina'] = 0;
    }

    // 7) Régénérer un peu de stamina du défenseur (5 points)
    $defender['stamina'] += 1;
    if ($defender['stamina'] > 100) {
        $defender['stamina'] = 100;
    }

    // 8) Vérifier KO
    $isKo = ($defender['hp'] <= 0);

    // 9) Générer un log
    $log = sprintf(
        '%s inflige %d dégâts à %s (tirage %d - défense %d). %s a %d de stamina (coût: -%d).',
        $attacker['name'],
        $damage,
        $defender['name'],
        $this->lastAttackDamage,
        $this->lastDefenseValue,
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

        // 2) Si la stamina est faible (< 20), la force diminue de 30%
        if ($attacker['stamina'] < 30) {
            $maxDamage = (int) round($maxDamage * 0.7);
        }

        $randomDamage = random_int($minDamage, $maxDamage);

        // 3) Appliquer le bonus de 15% à l'attaque de base
        $boostedDamage = (int) round($randomDamage * 1.15);
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

    private function evadeAttack(array $defender): bool
    {
        $chanceToEvade = min(50, $defender['agility'] * 2); // Max 50% d’esquive
        return random_int(1, 100) <= $chanceToEvade;
    }
}