<?php

namespace App\Service;

use App\Entity\Note;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Notification;

class NoteNotifier
{
    public function __construct(private NotificationService $notifications) {}

    /** Notifie toute la team sauf l’acteur */
    public function notifyTeam(Team $team, User $actor, string $title, ?string $message, ?string $url, ?object $entity = null): void
    {
        // owner
        if ($team->getOwner() && $team->getOwner()->getId() !== $actor->getId()) {
            $this->notifyOne($team->getOwner(), $title, $message, $url, $entity);
        }

        // members
        foreach ($team->getMembers() as $member) {
            $u = $member->getUser();
            if ($u->getId() === $actor->getId()) continue;
            $this->notifyOne($u, $title, $message, $url, $entity);
        }
    }

    public function notifyAssignees(Note $note, User $actor, string $title, ?string $message, ?string $url): void
    {
        foreach ($note->getAssignees() as $u) {
            if ($u->getId() === $actor->getId()) continue;
            $this->notifyOne($u, $title, $message, $url, $note);
        }
    }

    private function notifyOne(User $user, string $title, ?string $message, ?string $url, ?object $entity): void
    {
        if ($entity) {
            $this->notifications->createEntityNotification(
                user: $user,
                title: $title,
                relatedEntity: $entity,
                message: $message,
                type: Notification::TYPE_INFO,
                actionUrl: $url,
                icon: 'fa-regular fa-note-sticky',
                priority: Notification::PRIORITY_NORMAL
            );
            return;
        }

        $this->notifications->createNotification(
            user: $user,
            title: $title,
            message: $message,
            type: Notification::TYPE_INFO,
            actionUrl: $url,
            icon: 'fa-regular fa-note-sticky',
            priority: Notification::PRIORITY_NORMAL
        );
    }
}
