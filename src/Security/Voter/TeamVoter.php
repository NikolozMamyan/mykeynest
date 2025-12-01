<?php

namespace App\Security\Voter;

use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Repository\TeamMemberRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class TeamVoter extends Voter
{
    public const VIEW   = 'TEAM_VIEW';
    public const MANAGE = 'TEAM_MANAGE';

    public function __construct(
        private readonly TeamMemberRepository $teamMemberRepository
    ) {
    }

    protected function supports(string $attribute, $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::MANAGE], true)
            && $subject instanceof Team;
    }

    /**
     * @param Team $subject
     */
    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        $member = $this->teamMemberRepository->findOneBy([
            'team' => $subject,
            'user' => $user,
        ]);

        if (!$member instanceof TeamMember) {
            return false;
        }

        return match ($attribute) {
            self::VIEW   => true,
            self::MANAGE => \in_array($member->getRole(), [TeamRole::OWNER, TeamRole::ADMIN], true),
            default      => false,
        };
    }
}
