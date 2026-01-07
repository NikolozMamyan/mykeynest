<?php

namespace App\Service;

use App\Entity\Note;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Notification;
use Psr\Log\LoggerInterface;

class NoteNotifier
{
    public function __construct(
        private NotificationService $notifications,
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
        $title = 'New note created';
        $message = sprintf('"%s" has been created', $note->getTitle());
        
        $team = $note->getTeam();
        if ($team) {
            $url = sprintf('/app/notes/%d', $team->getId());
            $this->notifyTeam($team, $actor, $title, $message, $url, $note);
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

        $title = 'Assigned to note';
        $message = sprintf('You have been assigned to "%s"', $note->getTitle());
        
        $url = $note->getTeam() 
            ? sprintf('/app/notes/%d', $note->getTeam()->getId())
            : '/app/notes';
            
        $this->notifyOne($assignee, $title, $message, $url, $note);
    }

    /**
     * Notify about status change
     */
    public function notifyStatusChanged(Note $note, User $actor, string $oldStatus, string $newStatus): void
    {
        $title = 'Note status updated';
        $message = sprintf('"%s" moved from %s to %s', 
            $note->getTitle(), 
            $this->formatStatus($oldStatus),
            $this->formatStatus($newStatus)
        );
        
        $url = $note->getTeam() 
            ? sprintf('/app/notes/%d', $note->getTeam()->getId())
            : '/app/notes';

        // Notify assignees
        if ($note->getAssignments()->count() > 0) {
            $this->notifyAssignees($note, $actor, $title, $message, $url);
        }

        // Also notify team if it exists
        if ($note->getTeam()) {
            $this->notifyTeam($note->getTeam(), $actor, $title, $message, $url, $note);
        }
    }

    /**
     * Notify about note deletion
     */
    public function notifyNoteDeleted(Team $team, User $actor, string $noteTitle): void
    {
        $title = 'Note deleted';
        $message = sprintf('"%s" has been deleted', $noteTitle);
        $url = sprintf('/app/notes/%d', $team->getId());
        
        $this->notifyTeam($team, $actor, $title, $message, $url, null);
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
}