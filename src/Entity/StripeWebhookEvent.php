<?php

namespace App\Entity;

use App\Repository\StripeWebhookEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StripeWebhookEventRepository::class)]
#[ORM\Table(name: 'stripe_webhook_event')]
#[ORM\UniqueConstraint(name: 'uniq_stripe_webhook_event_id', fields: ['stripeEventId', 'stripeMode'])]
class StripeWebhookEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $stripeEventId;

    #[ORM\Column(length: 100)]
    private string $type;

    #[ORM\Column(length: 20, options: ['default' => 'production'])]
    private string $stripeMode;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $processedAt;

    public function __construct(string $stripeEventId, string $type, string $stripeMode = 'production')
    {
        $this->stripeEventId = $stripeEventId;
        $this->type = $type;
        $this->stripeMode = $stripeMode;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStripeEventId(): string
    {
        return $this->stripeEventId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStripeMode(): string
    {
        return $this->stripeMode;
    }

    public function getProcessedAt(): \DateTimeImmutable
    {
        return $this->processedAt;
    }
}
