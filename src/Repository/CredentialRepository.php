<?php

// src/Repository/CredentialRepository.php

namespace App\Repository;

use App\Entity\Credential;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Credential>
 */
class CredentialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Credential::class);
    }

    /**
     * @return Credential[] Returns an array of Credential objects
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.domain', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findCredentialsByUser(User $user): array
{
    return $this->createQueryBuilder('c')
        ->where('c.user = :user')
        ->setParameter('user', $user)
        ->orderBy('c.createdAt', 'DESC')
        ->getQuery()
        ->getResult();
}
    /**
     * @return Credential[] Returns an array of Credential objects
     */
    public function findByDomainAndUser(string $domain, User $user): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->andWhere('c.domain = :domain OR c.domain LIKE :subdomain')
            ->setParameter('user', $user)
            ->setParameter('domain', $domain)
            ->setParameter('subdomain', '%.'.$domain)
            ->getQuery()
            ->getResult();
    }

    public function countByUser($user): int
{
    return $this->createQueryBuilder('c')
        ->select('COUNT(c.id)')
        ->where('c.user = :user')
        ->setParameter('user', $user)
        ->getQuery()
        ->getSingleScalarResult();
}
}
