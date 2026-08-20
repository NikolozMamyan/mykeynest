<?php

namespace App\Entity;

use App\Repository\StripePaymentConfigurationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StripePaymentConfigurationRepository::class)]
#[ORM\Table(name: 'stripe_payment_configuration')]
#[ORM\UniqueConstraint(name: 'uniq_stripe_payment_configuration_key', fields: ['configurationKey'])]
class StripePaymentConfiguration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, options: ['default' => 'stripe'])]
    private string $configurationKey = 'stripe';

    #[ORM\Column(length: 20)]
    private string $activeMode = 'production';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $updatedBy = null;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActiveMode(): string
    {
        return $this->activeMode;
    }

    public function getConfigurationKey(): string
    {
        return $this->configurationKey;
    }

    public function setActiveMode(string $activeMode): static
    {
        $this->activeMode = $activeMode;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedBy(): ?string
    {
        return $this->updatedBy;
    }

    public function markUpdated(?string $updatedBy): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->updatedBy = $updatedBy;

        return $this;
    }
}
