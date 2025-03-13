<?php
namespace App\Service;

use App\Entity\Character;
use App\Repository\InventoryRepository;

class BattleService
{
    public function attack(array &$attacker, array &$defender): array
{
    // 1) Calcul des dégâts subis
    $damage = $this->calculateDamage($attacker, $defender);

    // 2) Appliquer les dégâts
    $this->applyDamage($defender, $damage);

    // 3) Vérifier KO
    $isKo = ($defender['hp'] <= 0);

    // 4) Générer un log
    $log = sprintf(
        '%s inflige %d dégâts à %s (tirage %d - défense %d)',
        $attacker['name'],
        $damage,
        $defender['name'],
        $this->lastAttackDamage,
        $this->lastDefenseValue
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
    $randomDamage = random_int($minDamage, $maxDamage);

    // 2) Appliquer le bonus de 15% à l'attaque de base
    $boostedDamage = (int) round($randomDamage * 1.15);
    $this->lastAttackDamage = $boostedDamage; // Stocker pour le log

    // 3) Calcul de la défense aléatoire
    $defenseValue = $this->calculateDefense($defender);
    $this->lastDefenseValue = $defenseValue; // Stocker pour le log

    // 4) Calcul des dégâts de base
    $damage = $boostedDamage - $defenseValue;

    // 5) Ajout d'un bonus aléatoire si les dégâts sont faibles
    if ($damage <= 1) {
        $bonous = random_int(1, 3);
        $bonusDamage = max(1, (int) round($boostedDamage * $bonous)); // Toujours min 1 dégât
        $damage += $bonusDamage;
    }

    return max(1, $damage); // Toujours infliger au moins 1 dégât
}

private function applyDamage(array &$defender, int $damage): void
{
    $defender['hp'] -= $damage;
    if ($defender['hp'] < 0) {
        $defender['hp'] = 0;
    }
}

private function calculateDefense(array $defender): int
{
    $minDefense = 0;
    $maxDefense = max(1, $defender['defense']);

    $chance = random_int(1, 100);

    if ($chance <= 60) {
        // ⚠ Assurer que le max ne soit pas inférieur au min
        $halfDefense = (int) floor($maxDefense / 2);
        return ($halfDefense >= $minDefense) ? random_int($minDefense, $halfDefense) : $minDefense;
    } else {
        // ⚠ Assurer que le min ne dépasse pas le max
        $halfDefense = (int) ceil($maxDefense / 2);
        return ($halfDefense <= $maxDefense) ? random_int($halfDefense, $maxDefense) : $maxDefense;
    }
}


}
