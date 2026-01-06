<?php

namespace App\Repository;

use App\Entity\Note;
use App\Entity\Team;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Note> */
class NoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Note::class);
    }

    /** @return Note[] */
    public function findByTeam(Team $team): array
    {
        return $this->createQueryBuilder('n')
            ->leftJoin('n.assignments', 'a')->addSelect('a')
            ->leftJoin('a.assignee', 'u')->addSelect('u')
            ->andWhere('n.team = :team')
            ->setParameter('team', $team)
            ->orderBy('n.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
