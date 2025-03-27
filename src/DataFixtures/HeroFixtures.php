<?php

namespace App\DataFixtures;

use App\Entity\Hero;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class HeroFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $heroes = [
            ['Street Fighter', '/images/sprites/streetfighter.webp'],
            ['Wrestler', '/images/sprites/wresler.png'],
            // ['Boxer', '/images/sprites/boxer.gif'],
            // ['Ninja', '/images/sprites/ninja.gif'],
            // ['Knight', '/images/sprites/knight.gif'],
        ];

        foreach ($heroes as [$className, $image]) {
            $hero = new Hero();
            $hero->setClassName($className);
            $hero->setImage($image);
            $manager->persist($hero);
        }

        $manager->flush();
    }
}
