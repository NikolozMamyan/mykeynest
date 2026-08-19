<?php

namespace App\Service;

use App\Entity\Credential;
use App\Entity\Team;
use App\Entity\TeamCredentialPermission;
use App\Repository\TeamCredentialPermissionRepository;
use Doctrine\ORM\EntityManagerInterface;

final class TeamCredentialPermissionManager
{
    public function __construct(
        private readonly TeamCredentialPermissionRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function setPasswordReveal(Team $team, Credential $credential, bool $allowed): void
    {
        $permission = $team->getId() === null
            ? null
            : $this->repository->findOneBy([
                'team' => $team,
                'credential' => $credential,
            ]);

        if (!$permission instanceof TeamCredentialPermission) {
            $permission = (new TeamCredentialPermission())
                ->setTeam($team)
                ->setCredential($credential);
            $this->entityManager->persist($permission);
        }

        $permission->setCanRevealPassword($allowed);
    }

    public function remove(Team $team, Credential $credential): void
    {
        $permission = $this->repository->findOneBy([
            'team' => $team,
            'credential' => $credential,
        ]);

        if ($permission instanceof TeamCredentialPermission) {
            $this->entityManager->remove($permission);
        }
    }
}
