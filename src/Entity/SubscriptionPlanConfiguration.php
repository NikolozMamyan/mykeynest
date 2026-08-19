<?php

namespace App\Entity;

use App\Repository\SubscriptionPlanConfigurationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubscriptionPlanConfigurationRepository::class)]
#[ORM\Table(name: 'subscription_plan_configuration')]
#[ORM\UniqueConstraint(name: 'uniq_subscription_plan_configuration_code', fields: ['planCode'])]
class SubscriptionPlanConfiguration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $planCode = 'free';

    /**
     * @var array<string, int|null>
     */
    #[ORM\Column(type: 'json')]
    private array $limits = [];

    /**
     * @var array<string, bool>
     */
    #[ORM\Column(type: 'json')]
    private array $features = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlanCode(): string
    {
        return $this->planCode;
    }

    public function setPlanCode(string $planCode): static
    {
        $this->planCode = mb_strtolower(trim($planCode));

        return $this;
    }

    /**
     * @return array<string, int|null>
     */
    public function getLimits(): array
    {
        return $this->limits;
    }

    /**
     * @param array<string, int|null> $limits
     */
    public function setLimits(array $limits): static
    {
        $this->limits = $limits;

        return $this;
    }

    /**
     * @return array<string, bool>
     */
    public function getFeatures(): array
    {
        return $this->features;
    }

    /**
     * @param array<string, bool> $features
     */
    public function setFeatures(array $features): static
    {
        $this->features = $features;

        return $this;
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
