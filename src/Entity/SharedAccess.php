<?php

namespace App\Entity;

use App\Repository\SharedAccessRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SharedAccessRepository::class)]
#[ORM\Index(name: 'IDX_SHARED_ACCESS_EXPIRY', columns: ['expires_at'])]
class SharedAccess
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $guest = null;

    #[ORM\ManyToOne(inversedBy: 'sharedAccesses')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Credential $credential = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $canRevealPassword = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $unlimitedOnActivation = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $ownerReminderSentAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $guestReminderSentAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $extensionRequestedAt = null;

    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function isUnlimitedOnActivation(): bool { return $this->unlimitedOnActivation; }
    public function getOwnerReminderSentAt(): ?\DateTimeImmutable { return $this->ownerReminderSentAt; }
    public function getGuestReminderSentAt(): ?\DateTimeImmutable { return $this->guestReminderSentAt; }
    public function getExtensionRequestedAt(): ?\DateTimeImmutable { return $this->extensionRequestedAt; }
    public function setOwnerReminderSentAt(?\DateTimeImmutable $date): self { $this->ownerReminderSentAt = $date; return $this; }
    public function setGuestReminderSentAt(?\DateTimeImmutable $date): self { $this->guestReminderSentAt = $date; return $this; }
    public function setExtensionRequestedAt(?\DateTimeImmutable $date): self { $this->extensionRequestedAt = $date; return $this; }

    public function setValidity(?\DateTimeImmutable $expiresAt, bool $unlimitedOnActivation = false): self
    {
        if ($this->expiresAt != $expiresAt) {
            $this->ownerReminderSentAt = $this->guestReminderSentAt = $this->extensionRequestedAt = null;
        }
        $this->expiresAt = $expiresAt;
        $this->unlimitedOnActivation = $expiresAt !== null && $unlimitedOnActivation;

        return $this;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getGuest(): ?User
    {
        return $this->guest;
    }

    public function setGuest(?User $guest): self
    {
        $this->guest = $guest;
        return $this;
    }

    public function getCredential(): ?Credential
    {
        return $this->credential;
    }

    public function setCredential(?Credential $credential): self
    {
        $this->credential = $credential;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function canRevealPassword(): bool
    {
        return $this->canRevealPassword;
    }

    public function setCanRevealPassword(bool $canRevealPassword): self
    {
        $this->canRevealPassword = $canRevealPassword;

        return $this;
    }
}
