<?php

namespace App\Security\Voter;

use App\Entity\Note;
use App\Entity\User;
use App\Enum\TeamRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class NoteVoter extends Voter
{
    public const VIEW = 'NOTE_VIEW';
    public const EDIT = 'NOTE_EDIT';
    public const DELETE = 'NOTE_DELETE';
    public const ASSIGN = 'NOTE_ASSIGN';
    public const STATUS = 'NOTE_STATUS';

protected function supports(string $attribute, mixed $subject): bool
{
    return $subject instanceof Note && in_array($attribute, [
        self::VIEW, self::EDIT, self::DELETE, self::ASSIGN, self::STATUS
    ], true);
}

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
{
    $user = $token->getUser();
    if (!$user instanceof User) return false;

    /** @var Note $note */
    $note = $subject;

    // Notes perso (sans team) : seul le créateur peut tout faire
    if ($note->getTeam() === null) {
        return $note->getCreatedBy()?->getId() === $user->getId();
    }

    $team = $note->getTeam();

    // Owner = ok
    if ($team->getOwner()?->getId() === $user->getId()) return true;

    // membre ?
    $isMember = false;
    foreach ($team->getMembers() as $member) {
        if ($member->getUser()->getId() === $user->getId()) {
            $isMember = true;
            break;
        }
    }
    if (!$isMember) return false;

    $isCreator = $note->getCreatedBy()?->getId() === $user->getId();
    $isAssignee = false;
    foreach ($note->getAssignments() as $a) {
        if ($a->getAssignee()->getId() === $user->getId()) { $isAssignee = true; break; }
    }

    return match ($attribute) {
        self::VIEW => true,
        self::STATUS => $isCreator || $isAssignee,          // ✅ assignee peut changer statut
        self::ASSIGN => $isCreator,                         // ajuste si tu veux admin
        self::EDIT, self::DELETE => $isCreator,             // idem
        default => false,
    };
}
}
