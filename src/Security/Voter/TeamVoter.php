<?php

namespace App\Security\Voter;

use App\Entity\Team;
use App\Entity\User;
use App\Enum\TeamRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class TeamVoter extends Voter
{
    const VIEW = 'TEAM_VIEW';
    const MANAGE = 'TEAM_MANAGE';
    const DELETE = 'TEAM_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::MANAGE, self::DELETE])
            && $subject instanceof Team;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Team $team */
        $team = $subject;

        return match($attribute) {
            self::VIEW => $this->canView($team, $user),
            self::MANAGE => $this->canManage($team, $user),
            self::DELETE => $this->canDelete($team, $user),
            default => false,
        };
    }

    private function canView(Team $team, User $user): bool
    {
        // Un utilisateur peut voir une équipe s'il en est membre
        foreach ($team->getMembers() as $member) {
            if ($member->getUser()->getId() === $user->getId()) {
                return true;
            }
        }

        return false;
    }

    private function canManage(Team $team, User $user): bool
    {
        // Un utilisateur peut gérer une équipe s'il est OWNER ou ADMIN
        foreach ($team->getMembers() as $member) {
            if ($member->getUser()->getId() === $user->getId()) {
                return in_array($member->getRole(), [TeamRole::OWNER, TeamRole::ADMIN]);
            }
        }

        return false;
    }

    private function canDelete(Team $team, User $user): bool
    {
        // Seul le propriétaire peut supprimer l'équipe
        return $team->getOwner()->getId() === $user->getId();
    }
}