<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class Note
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $content = null;

    #[ORM\Column(type: 'string', enumType: \App\Enum\NoteStatus::class)]
    private \App\Enum\NoteStatus $status = \App\Enum\NoteStatus::TODO;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dueAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $createdBy = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Team $team = null;

    #[ORM\OneToMany(mappedBy: 'note', targetEntity: NoteAssignment::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $assignments;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->assignments = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function getStatus(): \App\Enum\NoteStatus
    {
        return $this->status;
    }

    public function setStatus(\App\Enum\NoteStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getDueAt(): ?\DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function setDueAt(?\DateTimeImmutable $dueAt): static
    {
        $this->dueAt = $dueAt;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getTeam(): ?Team
    {
        return $this->team;
    }

    public function setTeam(?Team $team): static
    {
        $this->team = $team;
        return $this;
    }

    /**
     * @return Collection<int, NoteAssignment>
     */
    public function getAssignments(): Collection
    {
        return $this->assignments;
    }

    public function addAssignment(NoteAssignment $assignment): static
    {
        if (!$this->assignments->contains($assignment)) {
            $this->assignments->add($assignment);
            $assignment->setNote($this);
        }

        return $this;
    }

    public function removeAssignment(NoteAssignment $assignment): static
    {
        if ($this->assignments->removeElement($assignment)) {
            if ($assignment->getNote() === $this) {
                $assignment->setNote(null);
            }
        }

        return $this;
    }

    /**
     * Get all assignees (users assigned to this note)
     * 
     * @return User[]
     */
    public function getAssignees(): array
    {
        return $this->assignments->map(fn(NoteAssignment $a) => $a->getAssignee())->toArray();
    }

    /**
     * Check if a user is assigned to this note
     */
    public function isAssignedTo(User $user): bool
    {
        foreach ($this->assignments as $assignment) {
            if ($assignment->getAssignee()->getId() === $user->getId()) {
                return true;
            }
        }
        return false;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
    public function canEdit(User $user): bool
{
    return $this->getCreatedBy()?->getId() === $user->getId();
}

public function canChangeStatus(User $user): bool
{
    // owner OU assigné
    return $this->canEdit($user) || $this->isAssignedTo($user);
}

}