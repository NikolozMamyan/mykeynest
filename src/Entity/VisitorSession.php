<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'visitor_session')]
#[ORM\Index(name: 'idx_visitor_key_activity', columns: ['visitor_key', 'last_seen_at'])]
#[ORM\Index(name: 'idx_visitor_activity', columns: ['last_seen_at'])]
#[ORM\Index(name: 'idx_visitor_started', columns: ['started_at'])]
#[ORM\Index(name: 'idx_visitor_ip', columns: ['ip_address'])]
class VisitorSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $visitorKey;

    #[ORM\Column(length: 45)]
    private string $ipAddress;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $countryCode;

    #[ORM\Column(length: 500)]
    private string $userAgent;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(length: 1000)]
    private string $entryPath;

    #[ORM\Column(length: 1000)]
    private string $currentPath;

    #[ORM\Column]
    private int $pageViewCount;

    #[ORM\Column(length: 200)]
    private string $source;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $medium;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $campaign;

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getVisitorKey(): string
    {
        return $this->visitorKey;
    }
    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }
    public function getUserAgent(): string
    {
        return $this->userAgent;
    }
    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }
    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }
    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }
    public function getEntryPath(): string
    {
        return $this->entryPath;
    }
    public function getCurrentPath(): string
    {
        return $this->currentPath;
    }
    public function getPageViewCount(): int
    {
        return $this->pageViewCount;
    }
    public function getSource(): string
    {
        return $this->source;
    }
    public function getMedium(): ?string
    {
        return $this->medium;
    }
    public function getCampaign(): ?string
    {
        return $this->campaign;
    }
}
