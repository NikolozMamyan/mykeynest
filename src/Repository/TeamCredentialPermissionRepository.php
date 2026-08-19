<?php

namespace App\Repository;

use App\Entity\Credential;
use App\Entity\Team;
use App\Entity\TeamCredentialPermission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TeamCredentialPermission>
 */
class TeamCredentialPermissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeamCredentialPermission::class);
    }

    public function allowsPasswordReveal(Team $team, Credential $credential): bool
    {
        $permission = $this->findOneBy([
            'team' => $team,
            'credential' => $credential,
        ]);

        // Existing team shares predate permissions and must keep their current full access.
        return !$permission instanceof TeamCredentialPermission || $permission->canRevealPassword();
    }
}
