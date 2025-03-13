<?php

namespace App\DataFixtures;

use App\Entity\Character;
use App\Entity\Perk;
use App\Entity\Inventory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $perkSmallAttack = new Perk();
        $perkSmallAttack->setName('Small Attack Bonus')
            ->setType('attack')
            ->setValue(2);

        $perkBigAttack = new Perk();
        $perkBigAttack->setName('Big Attack Bonus')
            ->setType('attack')
            ->setValue(5);

        $perkSmallDefense = new Perk();
        $perkSmallDefense->setName('Small Defense Bonus')
            ->setType('defense')
            ->setValue(3);

        $perkBigDefense = new Perk();
        $perkBigDefense->setName('Big Defense Bonus')
            ->setType('defense')
            ->setValue(6);

        $manager->persist($perkSmallAttack);
        $manager->persist($perkBigAttack);
        $manager->persist($perkSmallDefense);
        $manager->persist($perkBigDefense);

        // --- 2) Création de quelques Characters ---
        $char1 = new Character();
        $char1->setName('Boxeur')
            ->setHp(100)
            ->setStrength(8)
            ->setDefense(5);

        $char2 = new Character();
        $char2->setName('Karateka')
            ->setHp(100)
            ->setStrength(7)
            ->setDefense(3);

        $char3 = new Character();
        $char3->setName('Thaiboxeur')
            ->setHp(120)
            ->setStrength(10)
            ->setDefense(4);

        $manager->persist($char1);
        $manager->persist($char2);
        $manager->persist($char3);

        // --- 3) Création des Inventories et liaison des perks ---
        // Pour le Boxeur
        $inventory1 = new Inventory();
        $inventory1->setCharacter($char1);
        // On lui donne "Small Attack Bonus" et "Small Defense Bonus"
        $inventory1->addPerk($perkSmallAttack);
        $inventory1->addPerk($perkSmallDefense);

        $manager->persist($inventory1);

        // Pour le Karateka
        $inventory2 = new Inventory();
        $inventory2->setCharacter($char2);
        // On lui donne "Big Attack Bonus" seulement
        $inventory2->addPerk($perkBigAttack);

        $manager->persist($inventory2);

        // Pour le Thaiboxeur
        $inventory3 = new Inventory();
        $inventory3->setCharacter($char3);
        // On lui donne "Big Defense Bonus" seulement
        $inventory3->addPerk($perkBigDefense);

        $manager->persist($inventory3);

        // --- 4) Flush final ---
        $manager->flush();
    }
}
