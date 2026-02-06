<?php

// src/Entity/Credential.php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Entity\SharedAccess;
use App\Entity\Team;
use App\Repository\CredentialRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CredentialRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Credential
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire')]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le domaine est obligatoire')]
    private ?string $domain = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le nom d\'utilisateur est obligatoire')]
    private ?string $username = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Le mot de passe est obligatoire')]
    private ?string $password = null;

    #[ORM\ManyToOne(inversedBy: 'credentials')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;
    /**
 * @var Collection<int, SharedAccess>
 */
#[ORM\OneToMany(mappedBy: 'credential', targetEntity: SharedAccess::class, orphanRemoval: true)]
private Collection $sharedAccesses;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

/**
 * @var Collection<int, Team>
 */
#[ORM\ManyToMany(targetEntity: Team::class, mappedBy: 'credentials')]
private Collection $teams;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->teams = new ArrayCollection();
        $this->sharedAccesses = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function setDomain(string $domain): static
    {
        $this->domain = $domain;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

/**
 * @return Collection<int, Team>
 */
public function getTeams(): Collection
{
    return $this->teams;
}

public function addTeam(Team $team): static
{
    if (!$this->teams->contains($team)) {
        $this->teams->add($team);
        $team->addCredential($this); // synchro inverse
    }

    return $this;
}

public function removeTeam(Team $team): static
{
    if ($this->teams->removeElement($team)) {
        $team->removeCredential($this); // synchro inverse
    }

    return $this;
}
/**
 * @return Collection<int, SharedAccess>
 */
public function getSharedAccesses(): Collection
{
    return $this->sharedAccesses;
}

public function addSharedAccess(SharedAccess $sharedAccess): static
{
    if (!$this->sharedAccesses->contains($sharedAccess)) {
        $this->sharedAccesses->add($sharedAccess);
        $sharedAccess->setCredential($this);
    }

    return $this;
}

public function removeSharedAccess(SharedAccess $sharedAccess): static
{
    if ($this->sharedAccesses->removeElement($sharedAccess)) {
        // set the owning side to null (unless already changed)
        if ($sharedAccess->getCredential() === $this) {
            $sharedAccess->setCredential(null); // ⚠️ attention credential est pas nullable
        }
    }

    return $this;
}


}
