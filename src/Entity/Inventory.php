<?php

namespace App\Entity;

use App\Repository\InventoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InventoryRepository::class)]
#[ORM\Table(name: '`inventory`')]
class Inventory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Relation OneToOne avec le Character.
     */
    #[ORM\OneToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: "CASCADE")]
    private ?Character $character = null;

    /**
     * Liste des perks disponibles dans l’inventaire.
     */
    #[ORM\ManyToMany(targetEntity: Perk::class)]
    #[ORM\JoinTable(name: '`inventory_perks`')]
    private Collection $perks;

    public function __construct()
    {
        $this->perks = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCharacter(): ?Character
    {
        return $this->character;
    }

    public function setCharacter(Character $character): self
    {
        $this->character = $character;

        return $this;
    }

    /**
     * @return Collection<int, Perk>
     */
    public function getPerks(): Collection
    {
        return $this->perks;
    }

    public function addPerk(Perk $perk): self
    {
        if (!$this->perks->contains($perk)) {
            $this->perks->add($perk);
        }

        return $this;
    }

    public function removePerk(Perk $perk): self
    {
        $this->perks->removeElement($perk);

        return $this;
    }
}
