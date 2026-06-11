<?php

namespace App\Repository;

use App\Entity\LoginChallenge;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LoginChallenge>
 */
class LoginChallengeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoginChallenge::class);
    }

    public function claimApproved(LoginChallenge $challenge, \DateTimeImmutable $completedAt): bool
    {
        $updatedRows = $this->createQueryBuilder('challenge')
            ->update()
            ->set('challenge.status', ':completed')
            ->set('challenge.completedAt', ':completedAt')
            ->where('challenge.id = :id')
            ->andWhere('challenge.status = :approved')
            ->andWhere('challenge.expiresAt > :completedAt')
            ->setParameter('completed', LoginChallenge::STATUS_COMPLETED)
            ->setParameter('completedAt', $completedAt)
            ->setParameter('id', $challenge->getId())
            ->setParameter('approved', LoginChallenge::STATUS_APPROVED)
            ->getQuery()
            ->execute();

        return $updatedRows === 1;
    }

    //    /**
    //     * @return LoginChallenge[] Returns an array of LoginChallenge objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('l')
    //            ->andWhere('l.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('l.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?LoginChallenge
    //    {
    //        return $this->createQueryBuilder('l')
    //            ->andWhere('l.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
