<?php

namespace App\Service;

use App\Entity\Note;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Notification;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class NoteNotifier
{
    public function __construct(
        private NotificationService $notifications,
        private TranslatorInterface $translator,
        private ?LoggerInterface $logger = null
    ) {}

    /**
     * Notify all team members except the actor
     */
    public function notifyTeam(
        Team $team, 
        User $actor, 
        string $title, 
        ?string $message, 
        ?string $url, 
        ?object $entity = null
    ): void {
        try {
            $recipients = $this->getTeamRecipients($team, $actor);
            
            foreach ($recipients as $recipient) {
                $this->notifyOne($recipient, $title, $message, $url, $entity);
            }
            
            $this->logger?->info('Team notification sent', [
                'team_id' => $team->getId(),
                'actor_id' => $actor->getId(),
                'recipients_count' => count($recipients),
                'title' => $title
            ]);
        } catch (\Exception $e) {
            $this->logger?->error('Failed to notify team', [
                'team_id' => $team->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify all assignees of a note except the actor
     */
    public function notifyAssignees(
        Note $note, 
        User $actor, 
        string $title, 
        ?string $message, 
        ?string $url
    ): void {
        try {
            $recipients = $this->getAssigneeRecipients($note, $actor);
            
            foreach ($recipients as $recipient) {
                $this->notifyOne($recipient, $title, $message, $url, $note);
            }
            
            $this->logger?->info('Assignees notification sent', [
                'note_id' => $note->getId(),
                'actor_id' => $actor->getId(),
                'recipients_count' => count($recipients),
                'title' => $title
            ]);
        } catch (\Exception $e) {
            $this->logger?->error('Failed to notify assignees', [
                'note_id' => $note->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify a single user
     */
    public function notifyUser(
        User $user,
        User $actor,
        string $title,
        ?string $message,
        ?string $url,
        ?object $entity = null
    ): void {
        // Don't notify the actor themselves
        if ($user->getId() === $actor->getId()) {
            return;
        }

        try {
            $this->notifyOne($user, $title, $message, $url, $entity);
            
            $this->logger?->info('User notification sent', [
                'user_id' => $user->getId(),
                'actor_id' => $actor->getId(),
                'title' => $title
            ]);
        } catch (\Exception $e) {
            $this->logger?->error('Failed to notify user', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify about note creation
     */
    public function notifyNoteCreated(Note $note, User $actor): void
    {
        $team = $note->getTeam();
        if ($team) {
            $url = sprintf('/app/notes/%d', $team->getId());
            foreach ($this->getTeamRecipients($team, $actor) as $recipient) {
                $this->notifyOne(
                    $recipient,
                    $this->trans($recipient, 'app_notifications.note.created_title'),
                    $this->trans($recipient, 'app_notifications.note.created_message', [
                        '%title%' => (string) $note->getTitle(),
                    ]),
                    $url,
                    $note
                );
            }
        }
    }

    /**
     * Notify about note assignment
     */
    public function notifyNoteAssigned(Note $note, User $assignee, User $actor): void
    {
        if ($assignee->getId() === $actor->getId()) {
            return;
        }

        $url = $note->getTeam() 
            ? sprintf('/app/notes/%d', $note->getTeam()->getId())
            : '/app/notes';
            
        $this->notifyOne(
            $assignee,
            $this->trans($assignee, 'app_notifications.note.assigned_title'),
            $this->trans($assignee, 'app_notifications.note.assigned_message', [
                '%title%' => (string) $note->getTitle(),
            ]),
            $url,
            $note
        );
    }

    /**
     * Notify about status change
     */
    public function notifyStatusChanged(Note $note, User $actor, string $oldStatus, string $newStatus): void
    {
        $url = $note->getTeam() 
            ? sprintf('/app/notes/%d', $note->getTeam()->getId())
            : '/app/notes';

        $this->notifyNoteRecipients(
            $note,
            $actor,
            'app_notifications.note.status_changed_title',
            'app_notifications.note.status_changed_message',
            [
                '%title%' => (string) $note->getTitle(),
                '%old_status%' => $this->formatStatus($oldStatus),
                '%new_status%' => $this->formatStatus($newStatus),
            ],
            $url
        );
    }

    /**
     * Notify about note deletion
     */
    public function notifyNoteDeleted(Team $team, User $actor, string $noteTitle): void
    {
        $url = sprintf('/app/notes/%d', $team->getId());

        foreach ($this->getTeamRecipients($team, $actor) as $recipient) {
            $this->notifyOne(
                $recipient,
                $this->trans($recipient, 'app_notifications.note.deleted_title'),
                $this->trans($recipient, 'app_notifications.note.deleted_message', [
                    '%title%' => $noteTitle,
                ]),
                $url,
                null
            );
        }
    }

    public function notifyNoteUnassigned(Note $note, User $assignee, User $actor): void
    {
        if ($assignee->getId() === $actor->getId()) {
            return;
        }

        $url = $note->getTeam()
            ? sprintf('/app/notes/%d', $note->getTeam()->getId())
            : '/app/notes';

        $this->notifyUser(
            $assignee,
            $actor,
            $this->trans($assignee, 'app_notifications.note.unassigned_title'),
            $this->trans($assignee, 'app_notifications.note.unassigned_message', [
                '%title%' => (string) $note->getTitle(),
            ]),
            $url,
            $note
        );
    }

    public function notifyNoteUpdated(
        Note $note,
        User $actor,
        string $fieldLabel,
        ?string $oldValue = null,
        ?string $newValue = null
    ): void {
        $url = $note->getTeam()
            ? sprintf('/app/notes/%d', $note->getTeam()->getId())
            : '/app/notes';

        $messageKey = ($oldValue !== null || $newValue !== null)
            ? 'app_notifications.note.updated_with_values_message'
            : 'app_notifications.note.updated_message';

        $this->notifyNoteRecipients(
            $note,
            $actor,
            'app_notifications.note.updated_title',
            $messageKey,
            [
                '%title%' => (string) $note->getTitle(),
                '%field%' => $fieldLabel,
                '%old%' => $oldValue ?: 'empty',
                '%new%' => $newValue ?: 'empty',
            ],
            $url
        );
    }

    /**
     * Get team members who should receive notifications (excluding actor)
     * 
     * @return User[]
     */
    private function getTeamRecipients(Team $team, User $actor): array
    {
        $recipients = [];
        
        // Add owner
        $owner = $team->getOwner();
        if ($owner && $owner->getId() !== $actor->getId()) {
            $recipients[$owner->getId()] = $owner;
        }
        
        // Add members
        foreach ($team->getMembers() as $member) {
            $user = $member->getUser();
            if ($user->getId() !== $actor->getId()) {
                $recipients[$user->getId()] = $user;
            }
        }
        
        return array_values($recipients);
    }

    /**
     * Get note assignees who should receive notifications (excluding actor)
     * 
     * @return User[]
     */
    private function getAssigneeRecipients(Note $note, User $actor): array
    {
        $recipients = [];
        
        foreach ($note->getAssignments() as $assignment) {
            $user = $assignment->getAssignee();
            if ($user->getId() !== $actor->getId()) {
                $recipients[$user->getId()] = $user;
            }
        }
        
        return array_values($recipients);
    }

    private function notifyNoteRecipients(
        Note $note,
        User $actor,
        string $titleKey,
        ?string $messageKey,
        array $parameters,
        string $url
    ): void {
        $recipients = [];

        foreach ($this->getAssigneeRecipients($note, $actor) as $recipient) {
            $recipients[$recipient->getId()] = $recipient;
        }

        if ($note->getTeam()) {
            foreach ($this->getTeamRecipients($note->getTeam(), $actor) as $recipient) {
                $recipients[$recipient->getId()] = $recipient;
            }
        }

        foreach (array_values($recipients) as $recipient) {
            $message = $messageKey === null
                ? null
                : $this->trans($recipient, $messageKey, $parameters);

            $this->notifyOne(
                $recipient,
                $this->trans($recipient, $titleKey, $parameters),
                $message,
                $url,
                $note
            );
        }
    }

    /**
     * Send notification to a single user
     */
    private function notifyOne(
        User $user, 
        string $title, 
        ?string $message, 
        ?string $url, 
        ?object $entity
    ): void {
        try {
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
            } else {
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
        } catch (\Exception $e) {
            $this->logger?->error('Failed to create notification', [
                'user_id' => $user->getId(),
                'title' => $title,
                'error' => $e->getMessage()
            ]);
            
            // Don't throw - we don't want notification failures to break the main flow
        }
    }

    /**
     * Format status for display
     */
    private function formatStatus(string $status): string
    {
        return match($status) {
            'todo' => 'To Do',
            'in_progress' => 'In Progress',
            'done' => 'Done',
            default => ucfirst(str_replace('_', ' ', $status))
        };
    }

    private function trans(User $user, string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, null, $user->getLocale());
    }
}
