<?php

namespace App\Repository;

use App\Entity\ExtensionClient;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ExtensionClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExtensionClient::class);
    }

    public function findOneByUserAndClientId(User $user, string $clientId): ?ExtensionClient
    {
        return $this->createQueryBuilder('ec')
            ->andWhere('ec.user = :user')
            ->andWhere('ec.clientId = :clientId')
            ->setParameter('user', $user)
            ->setParameter('clientId', $clientId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return ExtensionClient[]
     */
    public function findByUserOrderByLastSeen(User $user): array
    {
        return $this->createQueryBuilder('ec')
            ->andWhere('ec.user = :user')
            ->setParameter('user', $user)
            ->orderBy('ec.lastSeenAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return ExtensionClient[]
     */
    public function findBlockedByUser(User $user): array
    {
        return $this->createQueryBuilder('ec')
            ->andWhere('ec.user = :user')
            ->andWhere('ec.isBlocked = true')
            ->setParameter('user', $user)
            ->orderBy('ec.blockedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}