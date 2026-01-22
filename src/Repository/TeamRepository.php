<?php

namespace App\Repository;

use App\Entity\Team;
use App\Entity\User;
use App\Entity\Credential;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

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



public function findTeamWithCredentialsByUser(User $user): array
{
    return $this->createQueryBuilder('t')
        ->innerJoin('t.members', 'tm')
        ->addSelect('tm')
        ->innerJoin('tm.user', 'mu')
        ->addSelect('mu')
        ->innerJoin('t.owner', 'o')
        ->addSelect('o')
        ->andWhere('tm.user = :user')
        ->setParameter('user', $user)
        ->leftJoin('t.credentials', 'c')
        ->addSelect('c')
        ->addOrderBy('t.name', 'ASC')
        ->getQuery()
        ->getResult();
}
public function userHasTeamAccessToCredential(User $user, Credential $cred): bool
{
    return (bool) $this->createQueryBuilder('t')
        ->select('1')
        ->join('t.members', 'tm')          // TeamMember
        ->join('t.credentials', 'c')       // Credential
        ->andWhere('tm.user = :user')
        ->andWhere('c = :cred')
        ->setParameter('user', $user)
        ->setParameter('cred', $cred)
        ->setMaxResults(1)
        ->getQuery()
        ->getOneOrNullResult();
}


}
