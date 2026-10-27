<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'page_visit')]
#[ORM\Index(name: 'idx_page_visit_session', columns: ['session_id', 'visited_at'])]
#[ORM\Index(name: 'idx_page_visit_date', columns: ['visited_at'])]
class PageVisit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'session_id', nullable: false, onDelete: 'CASCADE')]
    private VisitorSession $session;

    #[ORM\Column(length: 1000)]
    private string $path;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $visitedAt;

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getPath(): string
    {
        return $this->path;
    }
    public function getVisitedAt(): \DateTimeImmutable
    {
        return $this->visitedAt;
    }
}
