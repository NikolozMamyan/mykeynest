<?php

namespace App\Entity;

use App\Repository\ExtensionClientRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExtensionClientRepository::class)]
#[ORM\Table(name: 'extension_client')]
#[ORM\UniqueConstraint(name: 'uniq_extension_client_user_client_id', fields: ['user', 'clientId'])]
#[ORM\Index(name: 'idx_extension_client_client_id', columns: ['client_id'])]
#[ORM\Index(name: 'idx_extension_client_last_seen_at', columns: ['last_seen_at'])]
class ExtensionClient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'extensionClients')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(name: 'client_id', length: 128)]
    private ?string $clientId = null;

    #[ORM\Column(name: 'device_label', length: 255, nullable: true)]
    private ?string $deviceLabel = null;

    #[ORM\Column(name: 'browser_name', length: 100, nullable: true)]
    private ?string $browserName = null;

    #[ORM\Column(name: 'browser_version', length: 50, nullable: true)]
    private ?string $browserVersion = null;

    #[ORM\Column(name: 'os_name', length: 100, nullable: true)]
    private ?string $osName = null;

    #[ORM\Column(name: 'os_version', length: 50, nullable: true)]
    private ?string $osVersion = null;

    #[ORM\Column(name: 'extension_version', length: 50, nullable: true)]
    private ?string $extensionVersion = null;

    #[ORM\Column(name: 'manifest_version', length: 20, nullable: true)]
    private ?string $manifestVersion = null;

    #[ORM\Column(name: 'origin_type', length: 20, nullable: true)]
    private ?string $originType = null;

    #[ORM\Column(name: 'last_ip_address', length: 45, nullable: true)]
    private ?string $lastIpAddress = null;

    #[ORM\Column(name: 'last_user_agent', length: 1000, nullable: true)]
    private ?string $lastUserAgent = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(name: 'first_seen_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $firstSeenAt = null;

    #[ORM\Column(name: 'last_seen_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(name: 'is_blocked', type: 'boolean', options: ['default' => false])]
    private bool $isBlocked = false;

    #[ORM\Column(name: 'blocked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $blockedAt = null;

    #[ORM\Column(name: 'blocked_reason', length: 255, nullable: true)]
    private ?string $blockedReason = null;

    #[ORM\Column(name: 'is_revoked', type: 'boolean', options: ['default' => false])]
    private bool $isRevoked = false;

    #[ORM\Column(name: 'revoked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(name: 'revoked_reason', length: 255, nullable: true)]
    private ?string $revokedReason = null;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->firstSeenAt = $now;
        $this->lastSeenAt = $now;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function setClientId(string $clientId): static
    {
        $this->clientId = trim($clientId);
        return $this;
    }

    public function getDeviceLabel(): ?string
    {
        return $this->deviceLabel;
    }

    public function setDeviceLabel(?string $deviceLabel): static
    {
        $this->deviceLabel = $deviceLabel ? trim($deviceLabel) : null;
        return $this;
    }

    public function getBrowserName(): ?string
    {
        return $this->browserName;
    }

    public function setBrowserName(?string $browserName): static
    {
        $this->browserName = $browserName ? trim($browserName) : null;
        return $this;
    }

    public function getBrowserVersion(): ?string
    {
        return $this->browserVersion;
    }

    public function setBrowserVersion(?string $browserVersion): static
    {
        $this->browserVersion = $browserVersion ? trim($browserVersion) : null;
        return $this;
    }

    public function getOsName(): ?string
    {
        return $this->osName;
    }

    public function setOsName(?string $osName): static
    {
        $this->osName = $osName ? trim($osName) : null;
        return $this;
    }

    public function getOsVersion(): ?string
    {
        return $this->osVersion;
    }

    public function setOsVersion(?string $osVersion): static
    {
        $this->osVersion = $osVersion ? trim($osVersion) : null;
        return $this;
    }

    public function getExtensionVersion(): ?string
    {
        return $this->extensionVersion;
    }

    public function setExtensionVersion(?string $extensionVersion): static
    {
        $this->extensionVersion = $extensionVersion ? trim($extensionVersion) : null;
        return $this;
    }

    public function getManifestVersion(): ?string
    {
        return $this->manifestVersion;
    }

    public function setManifestVersion(?string $manifestVersion): static
    {
        $this->manifestVersion = $manifestVersion ? trim($manifestVersion) : null;
        return $this;
    }

    public function getOriginType(): ?string
    {
        return $this->originType;
    }

    public function setOriginType(?string $originType): static
    {
        $this->originType = $originType ? trim($originType) : null;
        return $this;
    }

    public function getLastIpAddress(): ?string
    {
        return $this->lastIpAddress;
    }

    public function setLastIpAddress(?string $lastIpAddress): static
    {
        $this->lastIpAddress = $lastIpAddress;
        return $this;
    }

    public function getLastUserAgent(): ?string
    {
        return $this->lastUserAgent;
    }

    public function setLastUserAgent(?string $lastUserAgent): static
    {
        $this->lastUserAgent = $lastUserAgent;
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

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getFirstSeenAt(): ?\DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function setFirstSeenAt(\DateTimeImmutable $firstSeenAt): static
    {
        $this->firstSeenAt = $firstSeenAt;
        return $this;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(\DateTimeImmutable $lastSeenAt): static
    {
        $this->lastSeenAt = $lastSeenAt;
        return $this;
    }

    public function isBlocked(): bool
    {
        return $this->isBlocked;
    }

    public function setIsBlocked(bool $isBlocked): static
    {
        $this->isBlocked = $isBlocked;
        return $this;
    }

    public function getBlockedAt(): ?\DateTimeImmutable
    {
        return $this->blockedAt;
    }

    public function setBlockedAt(?\DateTimeImmutable $blockedAt): static
    {
        $this->blockedAt = $blockedAt;
        return $this;
    }

    public function getBlockedReason(): ?string
    {
        return $this->blockedReason;
    }

    public function setBlockedReason(?string $blockedReason): static
    {
        $this->blockedReason = $blockedReason;
        return $this;
    }

    public function isRevoked(): bool
    {
        return $this->isRevoked;
    }

    public function setIsRevoked(bool $isRevoked): static
    {
        $this->isRevoked = $isRevoked;
        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?\DateTimeImmutable $revokedAt): static
    {
        $this->revokedAt = $revokedAt;
        return $this;
    }

    public function getRevokedReason(): ?string
    {
        return $this->revokedReason;
    }

    public function setRevokedReason(?string $revokedReason): static
    {
        $this->revokedReason = $revokedReason;
        return $this;
    }
}