<?php

namespace App\Entity;

use App\Enum\OrganizationMemberStatus;
use App\Enum\OrganizationRole;
use App\Repository\OrganizationMemberRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrganizationMemberRepository::class)]
#[ORM\Table(name: 'organization_member')]
#[ORM\UniqueConstraint(name: 'uniq_organization_member_user', columns: ['organization_id', 'user_id'])]
class OrganizationMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organization::class, inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Organization $organization = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $invitedBy = null;

    #[ORM\Column(length: 20, enumType: OrganizationRole::class)]
    private OrganizationRole $role = OrganizationRole::MEMBER;

    #[ORM\Column(length: 20, enumType: OrganizationMemberStatus::class)]
    private OrganizationMemberStatus $status = OrganizationMemberStatus::PENDING;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $invitedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $invitationExpiresAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $joinedAt = null;

    public function __construct()
    {
        $this->invitedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization(?Organization $organization): static
    {
        $this->organization = $organization;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getInvitedBy(): ?User
    {
        return $this->invitedBy;
    }

    public function setInvitedBy(?User $invitedBy): static
    {
        $this->invitedBy = $invitedBy;

        return $this;
    }

    public function getRole(): OrganizationRole
    {
        return $this->role;
    }

    public function setRole(OrganizationRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getStatus(): OrganizationMemberStatus
    {
        return $this->status;
    }

    public function setStatus(OrganizationMemberStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getInvitedAt(): \DateTimeImmutable
    {
        return $this->invitedAt;
    }

    public function getInvitationExpiresAt(): ?\DateTimeImmutable
    {
        return $this->invitationExpiresAt;
    }

    public function setInvitationExpiresAt(?\DateTimeImmutable $invitationExpiresAt): static
    {
        $this->invitationExpiresAt = $invitationExpiresAt;

        return $this;
    }

    public function getJoinedAt(): ?\DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function activate(): static
    {
        $this->status = OrganizationMemberStatus::ACTIVE;
        $this->joinedAt ??= new \DateTimeImmutable();
        $this->invitationExpiresAt = null;

        return $this;
    }

    public function isInvitationExpired(): bool
    {
        return $this->status === OrganizationMemberStatus::PENDING
            && $this->invitationExpiresAt !== null
            && $this->invitationExpiresAt < new \DateTimeImmutable();
    }

    public function consumesSeat(): bool
    {
        return $this->role->consumesSeat() && $this->status->reservesSeat() && !$this->isInvitationExpired();
    }
}
