<?php
namespace App\Service;

use App\Repository\InventoryRepository;

class BattleService
{
    private InventoryRepository $inventoryRepo;

    public function __construct(InventoryRepository $inventoryRepo)
    {
        $this->inventoryRepo = $inventoryRepo;
    }

    /**
     * Effectue une attaque de `$attacker` sur `$defender` (données en tableau).
     * Retourne un tableau mis à jour avec les HP restants et les logs.
     */
    public function attack(array &$attacker, array &$defender): array
    {
        // 1) Calcul de la fourchette de dégâts aléatoires
        $minDamage = max(1, $attacker['strength'] - 4);
        $maxDamage = $attacker['strength'];
        $randomDamage = random_int($minDamage, $maxDamage);

        // 2) Ajuster avec la défense
        $damage = max(0, $randomDamage - $defender['defense']);

        // 3) Appliquer les dégâts
        $defender['hp'] -= $damage;
        if ($defender['hp'] < 0) {
            $defender['hp'] = 0;
        }

        // 4) Générer un log
        $log = sprintf(
            '%s inflige %d dégâts à %s (tirage %d - défense %d)',
            $attacker['name'],
            $damage,
            $defender['name'],
            $randomDamage,
            $defender['defense']
        );

        // 5) Vérifier KO
        $isKo = ($defender['hp'] <= 0);
        if ($isKo) {
            $log .= sprintf(' %s est K.O. !', $defender['name']);
        }

        return [
            'damage' => $damage,
            'log'    => $log,
            'isKo'   => $isKo,
        ];
    }
}
