<?php

namespace App\Entity;

use App\Repository\SupportConversationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SupportConversationRepository::class)]
class SupportConversation
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $publicToken = '';

    #[ORM\Column(length: 180)]
    private string $email = '';

    #[ORM\Column(length: 40)]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $unreadForAdmin = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $unreadForVisitor = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $lastMessageAt;

    /**
     * @var Collection<int, SupportMessage>
     */
    #[ORM\OneToMany(mappedBy: 'conversation', targetEntity: SupportMessage::class, orphanRemoval: true, cascade: ['persist'])]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $messages;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->lastMessageAt = $now;
        $this->messages = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicToken(): string
    {
        return $this->publicToken;
    }

    public function setPublicToken(string $publicToken): static
    {
        $this->publicToken = $publicToken;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        $this->touch();

        return $this;
    }

    public function isUnreadForAdmin(): bool
    {
        return $this->unreadForAdmin;
    }

    public function setUnreadForAdmin(bool $unreadForAdmin): static
    {
        $this->unreadForAdmin = $unreadForAdmin;
        $this->touch();

        return $this;
    }

    public function isUnreadForVisitor(): bool
    {
        return $this->unreadForVisitor;
    }

    public function setUnreadForVisitor(bool $unreadForVisitor): static
    {
        $this->unreadForVisitor = $unreadForVisitor;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getLastMessageAt(): \DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    public function setLastMessageAt(\DateTimeImmutable $lastMessageAt): static
    {
        $this->lastMessageAt = $lastMessageAt;
        $this->updatedAt = $lastMessageAt;

        return $this;
    }

    /**
     * @return Collection<int, SupportMessage>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(SupportMessage $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setConversation($this);
        }

        return $this;
    }

    public function removeMessage(SupportMessage $message): static
    {
        if ($this->messages->removeElement($message) && $message->getConversation() === $this) {
            $message->setConversation(null);
        }

        return $this;
    }

    public function touch(?\DateTimeImmutable $at = null): static
    {
        $this->updatedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }
}
