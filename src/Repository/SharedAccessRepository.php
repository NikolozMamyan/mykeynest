<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\SharedAccess;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<SharedAccessPhp>
 */
class SharedAccessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SharedAccess::class);
    }

    /**
     * Récupère les accès partagés à un utilisateur.
     *
     * @param User $user
     * @return SharedAccess[]
     */
    public function findSharedWith(User $user): array
    {
        return $this->createQueryBuilder('sa')
            ->andWhere('sa.guest = :user')
            ->setParameter('user', $user)
            ->leftJoin('sa.credential', 'c') // optionnel si tu veux charger les credentials
            ->addSelect('c')
            ->leftJoin('sa.owner', 'o') // optionnel si tu veux charger les owners
            ->addSelect('o')
            ->orderBy('sa.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
