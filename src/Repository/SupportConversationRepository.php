<?php

namespace App\Repository;

use App\Entity\SupportConversation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SupportConversation>
 */
class SupportConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupportConversation::class);
    }

    public function findOneByPublicToken(string $token): ?SupportConversation
    {
        return $this->findOneBy(['publicToken' => $token]);
    }

    public function findLatestByEmail(string $email): ?SupportConversation
    {
        return $this->createQueryBuilder('c')
            ->andWhere('LOWER(c.email) = :email')
            ->setParameter('email', mb_strtolower($email))
            ->orderBy('c.lastMessageAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<SupportConversation>
     */
    public function findInboxConversations(int $limit = 100): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.unreadForAdmin', 'DESC')
            ->addOrderBy('c.lastMessageAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countUnreadForAdmin(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.unreadForAdmin = :unread')
            ->setParameter('unread', true)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
