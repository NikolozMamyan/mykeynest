<?php

namespace App\Entity;

use App\Entity\User;
use App\Repository\CharacterRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CharacterRepository::class)]
#[ORM\Table(name: '`character`')]
class Character
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $name = null;

    #[ORM\Column]
    private int $hp = 0;

    #[ORM\Column]
    private int $strength = 0;

    #[ORM\Column]
    private int $defense = 0;

    #[ORM\Column]
    private int $speed = 0; // Ajout de speed

    #[ORM\Column]
    private int $agility = 0; // Ajout de agility
    #[ORM\Column]
    private int $stamina = 100;

    #[ORM\ManyToOne(inversedBy: 'characters')]
    #[ORM\JoinColumn(nullable: false)] 
    private ?User $owner = null;

    #[ORM\ManyToOne]
private ?Hero $hero = null;
    

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getHp(): int
    {
        return $this->hp;
    }

    public function setHp(int $hp): self
    {
        $this->hp = $hp;
        return $this;
    }


    public function getStrength(): int
    {
        return $this->strength;
    }

    public function setStrength(int $strength): self
    {
        $this->strength = $strength;
        return $this;
    }

    public function getDefense(): int
    {
        return $this->defense;
    }

    public function setDefense(int $defense): self
    {
        $this->defense = $defense;
        return $this;
    }
    public function getSpeed(): int
    {
        return $this->speed;
    }

    public function setSpeed(int $speed): self
    {
        $this->speed = $speed;
        return $this;
    }

    public function getAgility(): int
    {
        return $this->agility;
    }

    public function setAgility(int $agility): self
    {
        $this->agility = $agility;
        return $this;
    }
    public function getStamina(): int
    {
        return $this->stamina;
    }

    public function setStamina(int $stamina): self
    {
        $this->stamina = $stamina;
        return $this;
    }

    /**
     * Réduit les HP sans descendre sous 0.
     */
    public function takeDamage(int $damage): void
    {
        $this->hp = max(0, $this->hp - $damage);
    }
    public function getOwner(): ?User
{
    return $this->owner;
}

public function setOwner(?User $owner): self
{
    $this->owner = $owner;
    return $this;
}
public function getHero(): ?Hero
{
    return $this->hero;
}

public function setHero(?Hero $hero): static
{
    $this->hero = $hero;
    return $this;
}
}
