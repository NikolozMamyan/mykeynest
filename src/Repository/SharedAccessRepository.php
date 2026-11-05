<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\Credential;
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
            ->andWhere('sa.expiresAt IS NULL OR sa.expiresAt > :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user)
            ->leftJoin('sa.credential', 'c') // optionnel si tu veux charger les credentials
            ->addSelect('c')
            ->leftJoin('sa.owner', 'o') // optionnel si tu veux charger les owners
            ->addSelect('o')
            ->orderBy('sa.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
    public function userHasAccessToCredential(User $user, Credential $cred): bool
    {
        return (bool) $this->createQueryBuilder('sa')
            ->select('1')
            ->andWhere('sa.guest = :user')
            ->andWhere('sa.credential = :cred')
            ->andWhere('sa.expiresAt IS NULL OR sa.expiresAt > :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user)
            ->setParameter('cred', $cred)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function userCanRevealPassword(User $user, Credential $credential): bool
    {
        return (bool) $this->createQueryBuilder('sa')
            ->select('1')
            ->andWhere('sa.guest = :user')
            ->andWhere('sa.credential = :credential')
            ->andWhere('sa.canRevealPassword = true')
            ->andWhere('sa.expiresAt IS NULL OR sa.expiresAt > :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user)
            ->setParameter('credential', $credential)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<SharedAccess> */
    public function findDueForExpiryReminder(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('sa')
            ->andWhere('sa.expiresAt > :now AND sa.expiresAt <= :tomorrow')
            ->andWhere('sa.ownerReminderSentAt IS NULL OR sa.guestReminderSentAt IS NULL')
            ->setParameter('now', $now)->setParameter('tomorrow', $now->modify('+1 day'))
            ->orderBy('sa.expiresAt', 'ASC')->getQuery()->getResult();
    }
}
