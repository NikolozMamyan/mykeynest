<?php

namespace App\Entity;

use App\Entity\Character;
use App\Entity\Friendship;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 64, nullable: true)]
private ?string $apiToken = null;

#[ORM\Column(type: 'datetime', nullable: true)]
private ?\DateTimeInterface $tokenExpiresAt = null;

#[ORM\OneToMany(mappedBy: 'owner', targetEntity: Character::class, orphanRemoval: true)]
private Collection $characters;

#[ORM\OneToMany(mappedBy: 'requester', targetEntity: Friendship::class)]
private Collection $friendsRequested;

#[ORM\OneToMany(mappedBy: 'receiver', targetEntity: Friendship::class)]
private Collection $friendsReceived;


public function __construct()
{
    $this->characters = new ArrayCollection();
    $this->friendsRequested = new ArrayCollection();
    $this->friendsReceived = new ArrayCollection();
}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }
    public function getApiToken(): ?string
{
    return $this->apiToken;
}

public function setApiToken(?string $apiToken): static
{
    $this->apiToken = $apiToken;

    return $this;
}
public function getTokenExpiresAt(): ?\DateTimeInterface
{
    return $this->tokenExpiresAt;
}

public function setTokenExpiresAt(?\DateTimeInterface $expiresAt): static
{
    $this->tokenExpiresAt = $expiresAt;
    return $this;
}

public function getCharacters(): Collection
{
    return $this->characters;
}

public function addCharacter(Character $character): self
{
    if (!$this->characters->contains($character)) {
        $this->characters[] = $character;
        $character->setOwner($this);
    }

    return $this;
}

public function removeCharacter(Character $character): self
{
    if ($this->characters->removeElement($character)) {
        // Set the owning side to null (unless already changed)
        if ($character->getOwner() === $this) {
            $character->setOwner(null);
        }
    }

    return $this;
}
/**
 * @return Collection<int, Friendship>
 */
public function getFriendsRequested(): Collection
{
    return $this->friendsRequested;
}

public function addFriendRequested(Friendship $friendship): self
{
    if (!$this->friendsRequested->contains($friendship)) {
        $this->friendsRequested[] = $friendship;
        $friendship->setRequester($this);
    }

    return $this;
}

public function removeFriendRequested(Friendship $friendship): self
{
    if ($this->friendsRequested->removeElement($friendship)) {
        // set the owning side to null (unless already changed)
        if ($friendship->getRequester() === $this) {
            $friendship->setRequester(null);
        }
    }

    return $this;
}

/**
 * @return Collection<int, Friendship>
 */
public function getFriendsReceived(): Collection
{
    return $this->friendsReceived;
}

public function addFriendReceived(Friendship $friendship): self
{
    if (!$this->friendsReceived->contains($friendship)) {
        $this->friendsReceived[] = $friendship;
        $friendship->setReceiver($this);
    }

    return $this;
}

public function removeFriendReceived(Friendship $friendship): self
{
    if ($this->friendsReceived->removeElement($friendship)) {
        // set the owning side to null (unless already changed)
        if ($friendship->getReceiver() === $this) {
            $friendship->setReceiver(null);
        }
    }

    return $this;
}
public function getUsername(): string
{
    return $this->getEmail(); // ou $this->username si tu as un champ dédié
}

public function getFriends(): array
{
    $friends = [];

    foreach ($this->getFriendsRequested() as $friendship) {
        if ($friendship->isAccepted()) {
            $friends[] = $friendship->getReceiver();
        }
    }

    foreach ($this->getFriendsReceived() as $friendship) {
        if ($friendship->isAccepted()) {
            $friends[] = $friendship->getRequester();
        }
    }

    return $friends;
}


}
