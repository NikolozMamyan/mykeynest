<?php

namespace App\Tests\Service;

use App\Entity\Credential;
use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Service\NotificationService;
use App\Service\TeamNotifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TeamNotifierTest extends TestCase
{
    public function testNotifyMemberAddedTargetsOnlyNewMember(): void
    {
        $owner = $this->createUser(1, 'owner@example.test');
        $member = $this->createUser(2, 'member@example.test');

        $team = $this->createTeam(10, 'Ops', $owner);

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router
            ->expects($this->once())
            ->method('generate')
            ->with('app_team_show', ['id' => 10])
            ->willReturn('/app/teams/10');

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService
            ->expects($this->once())
            ->method('createEntityNotification')
            ->with(
                $this->identicalTo($member),
                'Added to team',
                $this->identicalTo($team),
                $this->stringContains('added you to team'),
                'info',
                '/app/teams/10',
                'fa-solid fa-users',
                'normal'
            )
            ->willReturn($this->createStub(\App\Entity\Notification::class));

        $notifier = new TeamNotifier($notificationService, $router, $this->createTranslator());

        $notifier->notifyMemberAdded($team, $member, $owner, TeamRole::MEMBER);
    }

    public function testNotifyCredentialsAddedTargetsAllOtherTeamUsersOnce(): void
    {
        $owner = $this->createUser(1, 'owner@example.test');
        $actor = $this->createUser(2, 'actor@example.test');
        $member = $this->createUser(3, 'member@example.test');

        $team = $this->createTeam(10, 'Ops', $owner);
        $team->addMember((new TeamMember())->setUser($actor)->setRole(TeamRole::ADMIN));
        $team->addMember((new TeamMember())->setUser($member)->setRole(TeamRole::MEMBER));

        $credential = (new Credential())
            ->setUser($owner)
            ->setName('Github')
            ->setDomain('github.com')
            ->setUsername('owner')
            ->setPassword('encrypted');

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router
            ->expects($this->exactly(2))
            ->method('generate')
            ->with('app_team_show', ['id' => 10])
            ->willReturn('/app/teams/10');

        $recipientIds = [];
        $notificationService = $this->createMock(NotificationService::class);
        $notificationService
            ->expects($this->exactly(2))
            ->method('createEntityNotification')
            ->willReturnCallback(function (User $user) use (&$recipientIds) {
                $recipientIds[] = $user->getId();

                return $this->createStub(\App\Entity\Notification::class);
            });

        $notifier = new TeamNotifier($notificationService, $router, $this->createTranslator());

        $notifier->notifyCredentialsAdded($team, $actor, [$credential]);

        sort($recipientIds);
        self::assertSame([1, 3], $recipientIds);
    }

    public function testNotifyMemberLeftTargetsOwner(): void
    {
        $owner = $this->createUser(1, 'owner@example.test');
        $member = $this->createUser(2, 'member@example.test');
        $team = $this->createTeam(10, 'Ops', $owner);

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router
            ->expects($this->once())
            ->method('generate')
            ->with('app_team_show', ['id' => 10])
            ->willReturn('/app/teams/10');

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService
            ->expects($this->once())
            ->method('createEntityNotification')
            ->with(
                $this->identicalTo($owner),
                'Team member left',
                $this->identicalTo($team),
                $this->stringContains('left team'),
                'info',
                '/app/teams/10',
                'fa-solid fa-users',
                'normal'
            )
            ->willReturn($this->createStub(\App\Entity\Notification::class));

        $notifier = new TeamNotifier($notificationService, $router, $this->createTranslator());

        $notifier->notifyMemberLeft($team, $member);
    }

    public function testNotifyMemberRemovedDoesNotIncludeActionUrl(): void
    {
        $owner = $this->createUser(1, 'owner@example.test');
        $member = $this->createUser(2, 'member@example.test');
        $team = $this->createTeam(10, 'Ops', $owner);

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router
            ->expects($this->never())
            ->method('generate');

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService
            ->expects($this->once())
            ->method('createEntityNotification')
            ->with(
                $this->identicalTo($member),
                'Removed from team',
                $this->identicalTo($team),
                $this->stringContains('removed you from team'),
                'info',
                null,
                'fa-solid fa-users',
                'normal'
            )
            ->willReturn($this->createStub(\App\Entity\Notification::class));

        $notifier = new TeamNotifier($notificationService, $router, $this->createTranslator());

        $notifier->notifyMemberRemoved($team, $member, $owner);
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

    private function createTeam(int $id, string $name, User $owner): Team
    {
        $team = (new Team())
            ->setName($name)
            ->setOwner($owner);

        $reflection = new \ReflectionProperty(Team::class, 'id');
        $reflection->setValue($team, $id);

        return $team;
    }

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnCallback(function (string $id, array $parameters = []) {
                $catalog = [
                    'app_notifications.team.member_added_title' => 'Added to team',
                    'app_notifications.team.member_added_message' => '%actor% added you to team "%team%" as %role%.',
                    'app_notifications.team.credentials_added_title' => 'Team credentials updated',
                    'app_notifications.team.credentials_added_single_message' => '%actor% added "%credential%" to team "%team%".',
                    'app_notifications.team.credentials_added_multiple_message' => '%actor% added %count% credentials to team "%team%".',
                    'app_notifications.team.member_left_title' => 'Team member left',
                    'app_notifications.team.member_left_message' => '%member% left team "%team%".',
                    'app_notifications.team.member_removed_title' => 'Removed from team',
                    'app_notifications.team.member_removed_message' => '%actor% removed you from team "%team%".',
                ];

                return strtr($catalog[$id] ?? $id, $parameters);
            });

        return $translator;
    }
}
