<?php

namespace App\Tests\Service;

use App\Entity\Note;
use App\Entity\NoteAssignment;
use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Service\NoteNotifier;
use App\Service\NotificationService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class NoteNotifierTest extends TestCase
{
    public function testNotifyStatusChangedDeduplicatesRecipients(): void
    {
        $owner = $this->createUser(1, 'owner@example.test');
        $actor = $this->createUser(2, 'actor@example.test');
        $member = $this->createUser(3, 'member@example.test');

        $team = (new Team())
            ->setName('Ops')
            ->setOwner($owner);

        $team->addMember(
            (new TeamMember())
                ->setUser($actor)
                ->setRole(TeamRole::MEMBER)
        );

        $team->addMember(
            (new TeamMember())
                ->setUser($member)
                ->setRole(TeamRole::MEMBER)
        );

        $note = (new Note())
            ->setTitle('Deploy checklist')
            ->setCreatedBy($owner)
            ->setTeam($team);

        $note->addAssignment(
            (new NoteAssignment())
                ->setNote($note)
                ->setAssignee($member)
                ->setAssignedBy($owner)
        );

        $recipientIds = [];
        $notificationService = $this->createMock(NotificationService::class);
        $notificationService
            ->expects($this->exactly(2))
            ->method('createEntityNotification')
            ->willReturnCallback(function (User $user) use (&$recipientIds) {
                $recipientIds[] = $user->getId();

                return $this->createStub(\App\Entity\Notification::class);
            });

        $notifier = new NoteNotifier($notificationService, $this->createTranslator());

        $notifier->notifyStatusChanged($note, $actor, 'todo', 'done');

        sort($recipientIds);
        self::assertSame([1, 3], $recipientIds);
    }

    public function testNotifyNoteUnassignedTargetsRemovedUser(): void
    {
        $owner = $this->createUser(1, 'owner@example.test');
        $assignee = $this->createUser(2, 'assignee@example.test');

        $note = (new Note())
            ->setTitle('Review contract')
            ->setCreatedBy($owner);

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService
            ->expects($this->once())
            ->method('createEntityNotification')
            ->with(
                $this->identicalTo($assignee),
                'Removed from note',
                $this->identicalTo($note),
                $this->stringContains('no longer assigned'),
                'info',
                '/app/notes',
                'fa-regular fa-note-sticky',
                'normal'
            )
            ->willReturn($this->createStub(\App\Entity\Notification::class));

        $notifier = new NoteNotifier($notificationService, $this->createTranslator());

        $notifier->notifyNoteUnassigned($note, $assignee, $owner);
    }

    private function createUser(int $id, string $email): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPassword('hashed')
            ->setCompany('Acme');

        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);

        return $user;
    }

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnCallback(function (string $id, array $parameters = []) {
                $catalog = [
                    'app_notifications.note.status_changed_title' => 'Note status updated',
                    'app_notifications.note.status_changed_message' => '"%title%" moved from %old_status% to %new_status%.',
                    'app_notifications.note.unassigned_title' => 'Removed from note',
                    'app_notifications.note.unassigned_message' => 'You are no longer assigned to "%title%".',
                ];

                return strtr($catalog[$id] ?? $id, $parameters);
            });

        return $translator;
    }
}
