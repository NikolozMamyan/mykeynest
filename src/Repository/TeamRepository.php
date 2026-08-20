<?php

namespace App\Repository;

use App\Entity\Team;
use App\Entity\User;
use App\Entity\Credential;
use App\Enum\OrganizationStatus;
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
            ->leftJoin('t.organization', 'organization')
            ->andWhere('m.user = :user')
            ->andWhere('organization.id IS NULL OR organization.status = :activeStatus OR t.owner = :user')
            ->setParameter('user', $user)
            ->setParameter('activeStatus', OrganizationStatus::ACTIVE->value)
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
        ->leftJoin('t.organization', 'organization')
        ->andWhere('tm.user = :user')
        ->andWhere('organization.id IS NULL OR organization.status = :activeStatus OR t.owner = :user')
        ->setParameter('user', $user)
        ->setParameter('activeStatus', OrganizationStatus::ACTIVE->value)
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
        ->leftJoin('t.organization', 'organization')
        ->andWhere('tm.user = :user')
        ->andWhere('c = :cred')
        ->andWhere('organization.id IS NULL OR organization.status = :activeStatus')
        ->setParameter('user', $user)
        ->setParameter('cred', $cred)
        ->setParameter('activeStatus', OrganizationStatus::ACTIVE->value)
        ->setMaxResults(1)
        ->getQuery()
        ->getOneOrNullResult();
}

/**
 * @return Team[]
 */
public function findTeamsForUserAndCredential(User $user, Credential $credential): array
{
    return $this->createQueryBuilder('t')
        ->join('t.members', 'tm')
        ->join('t.credentials', 'c')
        ->leftJoin('t.organization', 'organization')
        ->andWhere('tm.user = :user')
        ->andWhere('c = :credential')
        ->andWhere('organization.id IS NULL OR organization.status = :activeStatus')
        ->setParameter('user', $user)
        ->setParameter('credential', $credential)
        ->setParameter('activeStatus', OrganizationStatus::ACTIVE->value)
        ->getQuery()
        ->getResult();
}


}
