<?php

namespace App\Entity;

use App\Repository\TeamCredentialPermissionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TeamCredentialPermissionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_team_credential_permission', columns: ['team_id', 'credential_id'])]
class TeamCredentialPermission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Team $team = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Credential $credential = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $canRevealPassword = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTeam(): ?Team
    {
        return $this->team;
    }

    public function setTeam(Team $team): self
    {
        $this->team = $team;

        return $this;
    }

    public function getCredential(): ?Credential
    {
        return $this->credential;
    }

    public function setCredential(Credential $credential): self
    {
        $this->credential = $credential;

        return $this;
    }

    public function canRevealPassword(): bool
    {
        return $this->canRevealPassword;
    }

    public function setCanRevealPassword(bool $canRevealPassword): self
    {
        $this->canRevealPassword = $canRevealPassword;

        return $this;
    }
}
