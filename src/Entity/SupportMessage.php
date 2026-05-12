<?php

namespace App\Entity;

use App\Repository\SupportMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SupportMessageRepository::class)]
class SupportMessage
{
    public const AUTHOR_VISITOR = 'visitor';
    public const AUTHOR_ADMIN = 'admin';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?SupportConversation $conversation = null;

    #[ORM\Column(length: 40)]
    private string $authorType = self::AUTHOR_VISITOR;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $authorEmail = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $body = '';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): ?SupportConversation
    {
        return $this->conversation;
    }

    public function setConversation(?SupportConversation $conversation): static
    {
        $this->conversation = $conversation;

        return $this;
    }

    public function getAuthorType(): string
    {
        return $this->authorType;
    }

    public function setAuthorType(string $authorType): static
    {
        $this->authorType = $authorType;

        return $this;
    }

    public function getAuthorEmail(): ?string
    {
        return $this->authorEmail;
    }

    public function setAuthorEmail(?string $authorEmail): static
    {
        $this->authorEmail = $authorEmail;

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;

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
}
