<?php

namespace App\Service;

use App\Entity\Credential;
use App\Entity\User;
use App\Enum\OrganizationRole;
use App\Repository\OrganizationMemberRepository;
use App\Repository\SharedAccessRepository;
use App\Repository\TeamCredentialPermissionRepository;
use App\Repository\TeamRepository;

final class CredentialAccessPolicy
{
    public function __construct(
        private readonly SharedAccessRepository $sharedAccessRepository,
        private readonly TeamRepository $teamRepository,
        private readonly TeamCredentialPermissionRepository $teamCredentialPermissionRepository,
        private readonly OrganizationMemberRepository $organizationMemberRepository,
    ) {
    }

    public function canAccess(User $user, Credential $credential): bool
    {
        return $this->isOwner($user, $credential)
            || $this->sharedAccessRepository->userHasAccessToCredential($user, $credential)
            || $this->teamRepository->userHasTeamAccessToCredential($user, $credential);
    }

    public function canRevealPassword(User $user, Credential $credential): bool
    {
        if ($this->isOwner($user, $credential)) {
            return true;
        }

        if ($this->sharedAccessRepository->userCanRevealPassword($user, $credential)) {
            return true;
        }

        foreach ($this->teamRepository->findTeamsForUserAndCredential($user, $credential) as $team) {
            $organization = $team->getOrganization();
            if (
                $organization !== null
                && $this->organizationMemberRepository->findMembership($organization, $user)?->getRole() === OrganizationRole::GUEST
            ) {
                continue;
            }

            if ($this->teamCredentialPermissionRepository->allowsPasswordReveal($team, $credential)) {
                return true;
            }
        }

        return false;
    }

    public function canEdit(User $user, Credential $credential): bool
    {
        return $this->isOwner($user, $credential);
    }

    private function isOwner(User $user, Credential $credential): bool
    {
        $owner = $credential->getUser();
        if (!$owner instanceof User) {
            return false;
        }

        if ($owner->getId() !== null && $user->getId() !== null) {
            return $owner->getId() === $user->getId();
        }

        return $owner === $user;
    }
}
