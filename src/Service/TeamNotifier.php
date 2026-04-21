<?php

namespace App\Service;

use App\Entity\Credential;
use App\Entity\Notification;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\TeamRole;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TeamNotifier
{
    public function __construct(
        private NotificationService $notifications,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private ?LoggerInterface $logger = null
    ) {
    }

    public function notifyMemberAdded(Team $team, User $member, User $actor, TeamRole $role): void
    {
        if ($member->getId() === $actor->getId()) {
            return;
        }

        $message = $this->trans($member, 'app_notifications.team.member_added_message', [
            '%actor%' => (string) $actor->getEmail(),
            '%team%' => (string) $team->getName(),
            '%role%' => strtolower($role->value),
        ]);

        $this->notifyUser($member, $team, $this->trans($member, 'app_notifications.team.member_added_title'), $message);
    }

    /**
     * @param Credential[] $credentials
     */
    public function notifyCredentialsAdded(Team $team, User $actor, array $credentials): void
    {
        if ($credentials === []) {
            return;
        }

        $count = count($credentials);
        foreach ($this->getTeamRecipients($team, $actor) as $recipient) {
            $message = $count === 1
                ? $this->trans($recipient, 'app_notifications.team.credentials_added_single_message', [
                    '%actor%' => (string) $actor->getEmail(),
                    '%credential%' => (string) $credentials[0]->getName(),
                    '%team%' => (string) $team->getName(),
                ])
                : $this->trans($recipient, 'app_notifications.team.credentials_added_multiple_message', [
                    '%actor%' => (string) $actor->getEmail(),
                    '%count%' => $count,
                    '%team%' => (string) $team->getName(),
                ]);

            $this->notifyUser(
                $recipient,
                $team,
                $this->trans($recipient, 'app_notifications.team.credentials_added_title'),
                $message
            );
        }
    }

    public function notifyMemberRemoved(Team $team, User $member, User $actor): void
    {
        if ($member->getId() === $actor->getId()) {
            return;
        }

        $message = $this->trans($member, 'app_notifications.team.member_removed_message', [
            '%actor%' => (string) $actor->getEmail(),
            '%team%' => (string) $team->getName(),
        ]);

        $this->notifyUser(
            $member,
            $team,
            $this->trans($member, 'app_notifications.team.member_removed_title'),
            $message,
            false
        );
    }

    public function notifyMemberLeft(Team $team, User $member): void
    {
        $owner = $team->getOwner();
        if (!$owner || $owner->getId() === $member->getId()) {
            return;
        }

        $message = $this->trans($owner, 'app_notifications.team.member_left_message', [
            '%member%' => (string) $member->getEmail(),
            '%team%' => (string) $team->getName(),
        ]);

        $this->notifyUser($owner, $team, $this->trans($owner, 'app_notifications.team.member_left_title'), $message);
    }

    private function notifyUser(
        User $user,
        Team $team,
        string $title,
        string $message,
        bool $includeActionUrl = true
    ): void
    {
        try {
            $this->notifications->createEntityNotification(
                user: $user,
                title: $title,
                relatedEntity: $team,
                message: $message,
                type: Notification::TYPE_INFO,
                actionUrl: $includeActionUrl ? $this->urlGenerator->generate('app_team_show', ['id' => $team->getId()]) : null,
                icon: 'fa-solid fa-users',
                priority: Notification::PRIORITY_NORMAL
            );
        } catch (\Throwable $exception) {
            $this->logger?->error('Failed to create team notification', [
                'user_id' => $user->getId(),
                'team_id' => $team->getId(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return User[]
     */
    private function getTeamRecipients(Team $team, User $actor): array
    {
        $recipients = [];

        $owner = $team->getOwner();
        if ($owner && $owner->getId() !== $actor->getId()) {
            $recipients[$owner->getId()] = $owner;
        }

        foreach ($team->getMembers() as $member) {
            $user = $member->getUser();
            if ($user && $user->getId() !== $actor->getId()) {
                $recipients[$user->getId()] = $user;
            }
        }

        return array_values($recipients);
    }

    private function trans(User $user, string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, null, $user->getLocale());
    }
}
