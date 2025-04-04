<?php
namespace App\Entity;

use App\Repository\FriendshipRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FriendshipRepository::class)]
class Friendship
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $requester = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $receiver = null;

    #[ORM\Column(length: 20)]
    private string $status = 'pending'; 

    public function getId(): ?int { return $this->id; }

    public function getRequester(): ?User { return $this->requester; }
    public function setRequester(User $user): self { $this->requester = $user; return $this; }

    public function getReceiver(): ?User { return $this->receiver; }
    public function setReceiver(User $user): self { $this->receiver = $user; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function isAccepted(): bool
{
    return $this->status === 'accepted';
}

}

