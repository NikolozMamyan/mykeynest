<?php

namespace App\DataFixtures;

use App\Entity\Hero;
use App\Entity\User;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Bundle\FixturesBundle\Fixture;

class HeroFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $heroes = [
            ['Street Fighter', '/images/streetfighter.webp'],
            ['Wrestler', '/images/wresler.png'],
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
        $cpuUser = new User();
        $cpuUser->setEmail('cpu@bot.com');
        $cpuUser->setPassword('bot');
        $cpuUser->setRoles(['ROLE_CPU']);
        $manager->persist($cpuUser);

        $manager->flush();
    }
}
