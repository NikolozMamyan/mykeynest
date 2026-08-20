<?php

namespace App\Entity;

use App\Enum\OrganizationStatus;
use App\Repository\OrganizationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[ORM\Table(name: 'company_organization')]
class Organization
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $name = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?User $owner = null;

    #[ORM\Column(length: 20, enumType: OrganizationStatus::class)]
    private OrganizationStatus $status = OrganizationStatus::ACTIVE;

    /** @var Collection<int, OrganizationMember> */
    #[ORM\OneToMany(mappedBy: 'organization', targetEntity: OrganizationMember::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $members;

    /** @var Collection<int, Team> */
    #[ORM\OneToMany(mappedBy: 'organization', targetEntity: Team::class)]
    private Collection $teams;

    #[ORM\OneToOne(mappedBy: 'organization', targetEntity: UserSubscription::class)]
    private ?UserSubscription $subscription = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->members = new ArrayCollection();
        $this->teams = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this->touch();
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): static
    {
        $this->owner = $owner;

        return $this->touch();
    }

    public function getStatus(): OrganizationStatus
    {
        return $this->status;
    }

    public function setStatus(OrganizationStatus $status): static
    {
        $this->status = $status;

        return $this->touch();
    }

    public function isActive(): bool
    {
        return $this->status === OrganizationStatus::ACTIVE
            && $this->subscription?->isActive() === true
            && mb_strtolower((string) $this->subscription->getPlanCode()) === 'team';
    }

    /** @return Collection<int, OrganizationMember> */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(OrganizationMember $member): static
    {
        if (!$this->members->contains($member)) {
            $this->members->add($member);
            $member->setOrganization($this);
        }

        return $this;
    }

    public function removeMember(OrganizationMember $member): static
    {
        if ($this->members->removeElement($member) && $member->getOrganization() === $this) {
            $member->setOrganization(null);
        }

        return $this;
    }

    /** @return Collection<int, Team> */
    public function getTeams(): Collection
    {
        return $this->teams;
    }

    public function addTeam(Team $team): static
    {
        if (!$this->teams->contains($team)) {
            $this->teams->add($team);
            $team->setOrganization($this);
        }

        return $this;
    }

    public function removeTeam(Team $team): static
    {
        if ($this->teams->removeElement($team) && $team->getOrganization() === $this) {
            $team->setOrganization(null);
        }

        return $this;
    }

    public function getSubscription(): ?UserSubscription
    {
        return $this->subscription;
    }

    public function setSubscription(?UserSubscription $subscription): static
    {
        $this->subscription = $subscription;

        if ($subscription?->getOrganization() !== $this) {
            $subscription?->setOrganization($this);
        }

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
