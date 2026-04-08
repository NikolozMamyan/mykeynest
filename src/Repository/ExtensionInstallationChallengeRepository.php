<?php

namespace App\Repository;

use App\Entity\ExtensionInstallationChallenge;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExtensionInstallationChallenge>
 */
class ExtensionInstallationChallengeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExtensionInstallationChallenge::class);
    }

    public function findLatestByUserAndClientId(User $user, string $clientId): ?ExtensionInstallationChallenge
    {
        return $this->createQueryBuilder('challenge')
            ->andWhere('challenge.user = :user')
            ->andWhere('challenge.requestedClientId = :clientId')
            ->setParameter('user', $user)
            ->setParameter('clientId', $clientId)
            ->orderBy('challenge.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
