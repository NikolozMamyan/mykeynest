<?php

namespace App\Security\Voter;

use App\Entity\Credential;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class CredentialVoter extends Voter
{
    public const VIEW = 'CREDENTIAL_VIEW';
    public const EDIT = 'CREDENTIAL_EDIT';
    public const DELETE = 'CREDENTIAL_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Credential && \in_array($attribute, [
            self::VIEW,
            self::EDIT,
            self::DELETE,
        ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Credential $credential */
        $credential = $subject;

        if ($this->isOwner($credential, $user)) {
            return true;
        }

        return match ($attribute) {
            self::VIEW => $this->hasDirectShareAccess($credential, $user) || $this->hasTeamAccess($credential, $user),
            self::EDIT, self::DELETE => false,
            default => false,
        };
    }

    private function isOwner(Credential $credential, User $user): bool
    {
        return $this->sameUser($credential->getUser(), $user);
    }

    private function hasDirectShareAccess(Credential $credential, User $user): bool
    {
        foreach ($credential->getSharedAccesses() as $sharedAccess) {
            if ($this->sameUser($sharedAccess->getGuest(), $user)) {
                return true;
            }
        }

        return false;
    }

    private function hasTeamAccess(Credential $credential, User $user): bool
    {
        foreach ($credential->getTeams() as $team) {
            if ($this->sameUser($team->getOwner(), $user)) {
                return true;
            }

            foreach ($team->getMembers() as $member) {
                if ($this->sameUser($member->getUser(), $user)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function sameUser(?User $left, User $right): bool
    {
        if (!$left) {
            return false;
        }

        $leftId = $left->getId();
        $rightId = $right->getId();

        if ($leftId !== null && $rightId !== null) {
            return $leftId === $rightId;
        }

        return $left === $right;
    }
}
