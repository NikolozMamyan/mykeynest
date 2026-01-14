<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Validator\Constraints as Assert;
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

    #[ORM\Column(length: 255)]
    private ?string $company = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nom = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $prenom = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $apiExtensionToken = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isSubscribed = false;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatar = null;

#[ORM\Column(type: 'string', length: 20, nullable: true)]
#[Assert\Regex(
    pattern: '/^\+?[0-9\s\-]{6,20}$/',
    message: 'Phone number'
)]
private ?string $phone = null;

#[ORM\Column(type: 'string', length: 5, options: ['default' => 'fr'])]
    private string $locale = 'fr';

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $allowFeedback = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $interestedInCyberSecurity = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $receiveSecurityEmails = false;


public function __construct()
{
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

public function getCompany(): ?string
{
    return $this->company;
}

public function setCompany(string $company): static
{
    $this->company = $company;

    return $this;
}

public function getNom(): ?string
{
    return $this->nom;
}

public function setNom(?string $nom): static
{
    $this->nom = $nom;

    return $this;
}

public function getPrenom(): ?string
{
    return $this->prenom;
}

public function setPrenom(?string $prenom): static
{
    $this->prenom = $prenom;

    return $this;
}

public function getApiExtensionToken(): ?string
{
    return $this->apiExtensionToken;
}


public function regenerateApiExtensionToken(): static
{
    $this->apiExtensionToken = bin2hex(random_bytes(32)); // 64 caractères hexadécimaux
    return $this;
}
public function isSubscribed(): bool
{
    return $this->isSubscribed;
}

public function setIsSubscribed(bool $isSubscribed): static
{
    $this->isSubscribed = $isSubscribed;
    return $this;
}
public function getStripeCustomerId(): ?string
{
    return $this->stripeCustomerId;
}

public function setStripeCustomerId(?string $stripeCustomerId): static
{
    $this->stripeCustomerId = $stripeCustomerId;
    return $this;
}

public function getStripeSubscriptionId(): ?string
{
    return $this->stripeSubscriptionId;
}

public function setStripeSubscriptionId(?string $stripeSubscriptionId): static
{
    $this->stripeSubscriptionId = $stripeSubscriptionId;
    return $this;
}
public function getAvatar(): ?string
{
    return $this->avatar;
}

public function setAvatar(?string $avatar): self
{
    $this->avatar = $avatar;

    return $this;
}
public function getPhone(): ?string
{
    return $this->phone;
}

public function setPhone(?string $phone): self
{
    $this->phone = $phone;

    return $this;
}

  public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;
        return $this;
    }

    public function isAllowFeedback(): bool
    {
        return $this->allowFeedback;
    }

    public function setAllowFeedback(bool $allowFeedback): self
    {
        $this->allowFeedback = $allowFeedback;
        return $this;
    }

    public function isInterestedInCyberSecurity(): bool
    {
        return $this->interestedInCyberSecurity;
    }

    public function setInterestedInCyberSecurity(bool $interestedInCyberSecurity): self
    {
        $this->interestedInCyberSecurity = $interestedInCyberSecurity;
        return $this;
    }

    public function isReceiveSecurityEmails(): bool
    {
        return $this->receiveSecurityEmails;
    }

    public function setReceiveSecurityEmails(bool $receiveSecurityEmails): self
    {
        $this->receiveSecurityEmails = $receiveSecurityEmails;
        return $this;
    }
}
