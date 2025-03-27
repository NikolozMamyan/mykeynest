<?php

namespace App\DataFixtures;

use App\Entity\Perk;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class PerkFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $perks = [
            [
                'name' => 'Basic Attack',
                'value' => 3,
                'type' => 'strength',
            ],
            [
                'name' => 'Iron Shield',
                'value' => 4,
                'type' => 'defense',
            ],
            [
                'name' => 'Swift Feet',
                'value' => 3,
                'type' => 'agility',
            ],
        ];

        foreach ($perks as $data) {
            $perk = new Perk();
            $perk->setName($data['name']);
            $perk->setValue($data['value']);
            $perk->setType($data['type']);
            $manager->persist($perk);
        }

        $manager->flush();
    }
}
