<?php

namespace App\Repository;

use App\Entity\StripeWebhookEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StripeWebhookEvent>
 */
final class StripeWebhookEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StripeWebhookEvent::class);
    }

    public function hasProcessed(string $stripeEventId, string $stripeMode = 'production'): bool
    {
        return $this->count([
            'stripeEventId' => $stripeEventId,
            'stripeMode' => $stripeMode,
        ]) > 0;
    }
}
