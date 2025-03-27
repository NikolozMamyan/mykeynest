<?php
namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Character;
use App\Entity\Hero;
use App\Entity\User;

class BotService
{
    public function __construct(private EntityManagerInterface $em) {}

    public function generateBotFor(Character $player): Character
    {
        $playerLevel = $player->getLevel();
        $min = max(1, $playerLevel - 3);
        $max = $playerLevel + 3;
        $botLevel = random_int($min, $max);

        $bot = new Character();
        $bot->setName('CPU-' . uniqid());
        $bot->setLevel($botLevel);
        $bot->setStrength(random_int(1, 5 + $botLevel));
        $bot->setDefense(random_int(1, 5 + $botLevel));
        $bot->setSpeed(random_int(1, 5 + $botLevel));
        $bot->setAgility(random_int(1, 5 + $botLevel));
        $bot->setHp(100);
        $bot->setStamina(100);

        $heroes = $this->em->getRepository(Hero::class)->findAll();
        $bot->setHero($heroes[array_rand($heroes)]);

        $cpuUser = $this->em->getRepository(User::class)->findOneBy(['email' => 'cpu@bot.com']);
        if (!$cpuUser) {
            throw new \LogicException("CPU user not found.");
        }

        $bot->setOwner($cpuUser);

        $this->em->persist($bot);
        $this->em->flush();

        return $bot;
    }
}
