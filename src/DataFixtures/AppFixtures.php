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
       
        $manager->flush();
    }
}
