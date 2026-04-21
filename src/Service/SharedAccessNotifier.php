<?php

namespace App\Service;

use App\Entity\Credential;
use App\Entity\Notification;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SharedAccessNotifier
{
    public function __construct(
        private NotificationService $notifications,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private ?LoggerInterface $logger = null
    ) {
    }

    /**
     * @param Credential[] $credentials
     */
    public function notifyCredentialsShared(User $guest, User $owner, array $credentials): void
    {
        if ($guest->getId() === $owner->getId() || $credentials === []) {
            return;
        }

        $count = count($credentials);
        $firstCredential = $credentials[0];

        if (!$firstCredential instanceof Credential) {
            return;
        }

        $title = $this->trans(
            $guest,
            $count === 1
                ? 'app_notifications.shared_access.shared_single_title'
                : 'app_notifications.shared_access.shared_multiple_title'
        );
        $message = $this->trans(
            $guest,
            $count === 1
                ? 'app_notifications.shared_access.shared_single_message'
                : 'app_notifications.shared_access.shared_multiple_message',
            [
                '%owner%' => (string) $owner->getEmail(),
                '%credential%' => (string) $firstCredential->getName(),
                '%count%' => $count,
            ]
        );

        $this->notify(
            $guest,
            $title,
            $message,
            $this->urlGenerator->generate('shared_access_index'),
            $firstCredential
        );
    }

    public function notifyShareRevoked(User $guest, User $owner, Credential $credential): void
    {
        if ($guest->getId() === $owner->getId()) {
            return;
        }

        $message = $this->trans($guest, 'app_notifications.shared_access.revoked_message', [
            '%owner%' => (string) $owner->getEmail(),
            '%credential%' => (string) $credential->getName(),
        ]);

        $this->notify(
            $guest,
            $this->trans($guest, 'app_notifications.shared_access.revoked_title'),
            $message,
            $this->urlGenerator->generate('shared_access_index'),
            $credential
        );
    }

    private function notify(
        User $user,
        string $title,
        string $message,
        string $actionUrl,
        Credential $credential
    ): void {
        try {
            $this->notifications->createEntityNotification(
                user: $user,
                title: $title,
                relatedEntity: $credential,
                message: $message,
                type: Notification::TYPE_INFO,
                actionUrl: $actionUrl,
                icon: 'fa-solid fa-share-nodes',
                priority: Notification::PRIORITY_NORMAL
            );
        } catch (\Throwable $exception) {
            $this->logger?->error('Failed to create shared access notification', [
                'user_id' => $user->getId(),
                'credential_id' => $credential->getId(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function trans(User $user, string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, null, $user->getLocale());
    }
}
