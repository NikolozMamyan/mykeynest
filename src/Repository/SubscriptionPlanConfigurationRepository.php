<?php

namespace App\Repository;

use App\Entity\SubscriptionPlanConfiguration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SubscriptionPlanConfiguration>
 */
class SubscriptionPlanConfigurationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SubscriptionPlanConfiguration::class);
    }

    public function findByPlanCode(string $planCode): ?SubscriptionPlanConfiguration
    {
        return $this->findOneBy(['planCode' => mb_strtolower(trim($planCode))]);
    }
}
