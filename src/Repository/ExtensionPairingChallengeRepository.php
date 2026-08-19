<?php

namespace App\Repository;

use App\Entity\ExtensionPairingChallenge;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExtensionPairingChallenge>
 */
class ExtensionPairingChallengeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExtensionPairingChallenge::class);
    }

    /**
     * @return ExtensionPairingChallenge[]
     */
    public function findOpenByUserAndClientId(User $user, string $clientId): array
    {
        return $this->createQueryBuilder('challenge')
            ->andWhere('challenge.user = :user')
            ->andWhere('challenge.clientId = :clientId')
            ->andWhere('challenge.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('clientId', $clientId)
            ->setParameter('statuses', [
                ExtensionPairingChallenge::STATUS_PENDING,
                ExtensionPairingChallenge::STATUS_EXCHANGED,
            ])
            ->getQuery()
            ->getResult();
    }

    public function findByTokenHashForUpdate(string $tokenHash): ?ExtensionPairingChallenge
    {
        return $this->createQueryBuilder('challenge')
            ->andWhere('challenge.tokenHash = :tokenHash')
            ->setParameter('tokenHash', $tokenHash)
            ->setMaxResults(1)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    public function findForCompletion(User $user, string $publicId, string $clientId): ?ExtensionPairingChallenge
    {
        return $this->createQueryBuilder('challenge')
            ->andWhere('challenge.user = :user')
            ->andWhere('challenge.publicId = :publicId')
            ->andWhere('challenge.clientId = :clientId')
            ->setParameter('user', $user)
            ->setParameter('publicId', $publicId)
            ->setParameter('clientId', $clientId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
