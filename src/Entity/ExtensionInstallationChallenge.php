<?php

namespace App\Entity;

use App\Repository\ExtensionInstallationChallengeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExtensionInstallationChallengeRepository::class)]
#[ORM\Table(name: 'extension_installation_challenge')]
#[ORM\Index(columns: ['token_hash'], name: 'idx_ext_install_challenge_token_hash')]
#[ORM\Index(columns: ['public_id'], name: 'idx_ext_install_challenge_public_id')]
#[ORM\Index(columns: ['requested_client_id'], name: 'idx_ext_install_challenge_client_id')]
class ExtensionInstallationChallenge
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_COMPLETED = 'completed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'public_id', length: 36, unique: true)]
    private ?string $publicId = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(name: 'token_hash', length: 64, unique: true)]
    private ?string $tokenHash = null;

    #[ORM\Column(name: 'requested_client_id', length: 128)]
    private ?string $requestedClientId = null;

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

    #[ORM\Column(name: 'ip_address', length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(name: 'user_agent', length: 1000, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $rejectedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = new \DateTimeImmutable('+15 minutes');
        $this->publicId = $this->generateUuidV4();
    }

    private function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function getId(): ?int { return $this->id; }
    public function getPublicId(): ?string { return $this->publicId; }
    public function setPublicId(string $publicId): static { $this->publicId = $publicId; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }
    public function getTokenHash(): ?string { return $this->tokenHash; }
    public function setTokenHash(string $tokenHash): static { $this->tokenHash = $tokenHash; return $this; }
    public function getRequestedClientId(): ?string { return $this->requestedClientId; }
    public function setRequestedClientId(string $requestedClientId): static { $this->requestedClientId = trim($requestedClientId); return $this; }
    public function getDeviceLabel(): ?string { return $this->deviceLabel; }
    public function setDeviceLabel(?string $deviceLabel): static { $this->deviceLabel = $deviceLabel ? trim($deviceLabel) : null; return $this; }
    public function getBrowserName(): ?string { return $this->browserName; }
    public function setBrowserName(?string $browserName): static { $this->browserName = $browserName ? trim($browserName) : null; return $this; }
    public function getBrowserVersion(): ?string { return $this->browserVersion; }
    public function setBrowserVersion(?string $browserVersion): static { $this->browserVersion = $browserVersion ? trim($browserVersion) : null; return $this; }
    public function getOsName(): ?string { return $this->osName; }
    public function setOsName(?string $osName): static { $this->osName = $osName ? trim($osName) : null; return $this; }
    public function getOsVersion(): ?string { return $this->osVersion; }
    public function setOsVersion(?string $osVersion): static { $this->osVersion = $osVersion ? trim($osVersion) : null; return $this; }
    public function getExtensionVersion(): ?string { return $this->extensionVersion; }
    public function setExtensionVersion(?string $extensionVersion): static { $this->extensionVersion = $extensionVersion ? trim($extensionVersion) : null; return $this; }
    public function getManifestVersion(): ?string { return $this->manifestVersion; }
    public function setManifestVersion(?string $manifestVersion): static { $this->manifestVersion = $manifestVersion ? trim($manifestVersion) : null; return $this; }
    public function getOriginType(): ?string { return $this->originType; }
    public function setOriginType(?string $originType): static { $this->originType = $originType ? trim($originType) : null; return $this; }
    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $ipAddress): static { $this->ipAddress = $ipAddress; return $this; }
    public function getUserAgent(): ?string { return $this->userAgent; }
    public function setUserAgent(?string $userAgent): static { $this->userAgent = $userAgent; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $expiresAt): static { $this->expiresAt = $expiresAt; return $this; }
    public function getApprovedAt(): ?\DateTimeImmutable { return $this->approvedAt; }
    public function setApprovedAt(?\DateTimeImmutable $approvedAt): static { $this->approvedAt = $approvedAt; return $this; }
    public function getRejectedAt(): ?\DateTimeImmutable { return $this->rejectedAt; }
    public function setRejectedAt(?\DateTimeImmutable $rejectedAt): static { $this->rejectedAt = $rejectedAt; return $this; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $completedAt): static { $this->completedAt = $completedAt; return $this; }
    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
    public function isApproved(): bool { return $this->status === self::STATUS_APPROVED; }
    public function isRejected(): bool { return $this->status === self::STATUS_REJECTED; }
    public function isCompleted(): bool { return $this->status === self::STATUS_COMPLETED; }

    public function isExpired(): bool
    {
        return $this->expiresAt <= new \DateTimeImmutable();
    }
}
