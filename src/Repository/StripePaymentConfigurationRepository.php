<?php

namespace App\Repository;

use App\Entity\StripePaymentConfiguration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StripePaymentConfiguration> */
class StripePaymentConfigurationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StripePaymentConfiguration::class);
    }

    public function findConfiguration(): ?StripePaymentConfiguration
    {
        return $this->findOneBy(['configurationKey' => 'stripe']);
    }
}
