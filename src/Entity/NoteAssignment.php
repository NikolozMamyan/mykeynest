<?php

namespace App\Entity;

use App\Repository\NoteAssignmentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NoteAssignmentRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_note_assignee', fields: ['note', 'assignee'])]
class NoteAssignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Note::class, inversedBy: 'assignments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Note $note = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $assignee = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $assignedBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $assignedAt;

    public function __construct()
    {
        $this->assignedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getNote(): ?Note { return $this->note; }
    public function setNote(Note $note): static { $this->note = $note; return $this; }

    public function getAssignee(): User { return $this->assignee; }
    public function setAssignee(User $user): static { $this->assignee = $user; return $this; }

    public function getAssignedBy(): User { return $this->assignedBy; }
    public function setAssignedBy(User $user): static { $this->assignedBy = $user; return $this; }

    public function getAssignedAt(): \DateTimeImmutable { return $this->assignedAt; }
}
