<?php

namespace App\Repository;

use App\Entity\Article;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Article>
 */
class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

    public function findOneBySlugAndLocale(string $slug, string $locale): ?Article
{
    $field = $locale === 'fr' ? 'a.slugFr' : 'a.slugEn';

    return $this->createQueryBuilder('a')
        ->andWhere($field . ' = :slug')
        ->setParameter('slug', $slug)
        ->getQuery()
        ->getOneOrNullResult();
}


public function findPaginated(int $page, int $perPage): array
{
    $qb = $this->createQueryBuilder('a')
        ->orderBy('a.publishedAt', 'DESC');

    $total = (int) (clone $qb)
        ->select('COUNT(a.id)')
        ->getQuery()
        ->getSingleScalarResult();

    $items = $qb
        ->setFirstResult(($page - 1) * $perPage)
        ->setMaxResults($perPage)
        ->getQuery()
        ->getResult();

    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'perPage' => $perPage,
        'pages' => max(1, (int) ceil($total / $perPage)),
    ];
}


}
