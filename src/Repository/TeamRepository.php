<?php

namespace App\Repository;

use App\Entity\Team;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Team>
 */
class TeamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Team::class);
    }

    /**
     * Retourne toutes les équipes dont l'utilisateur est membre.
     *
     * @return Team[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.members', 'm')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user)
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
