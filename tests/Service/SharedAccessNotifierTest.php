<?php

namespace App\Tests\Service;

use App\Entity\Credential;
use App\Entity\User;
use App\Service\NotificationService;
use App\Service\SharedAccessNotifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SharedAccessNotifierTest extends TestCase
{
    public function testNotifyCredentialsSharedCreatesSingleNotification(): void
    {
        $owner = $this->createUser(1, 'owner@example.test');
        $guest = $this->createUser(2, 'guest@example.test');
        $credential = $this->createCredential(10, $owner, 'Github');

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router
            ->expects($this->once())
            ->method('generate')
            ->with('shared_access_index')
            ->willReturn('/app/shared-access');

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService
            ->expects($this->once())
            ->method('createEntityNotification')
            ->with(
                $this->identicalTo($guest),
                'Credential shared with you',
                $this->identicalTo($credential),
                $this->stringContains('Github'),
                'info',
                '/app/shared-access',
                'fa-solid fa-share-nodes',
                'normal'
            )
            ->willReturn($this->createStub(\App\Entity\Notification::class));

        $notifier = new SharedAccessNotifier($notificationService, $router, $this->createTranslator());

        $notifier->notifyCredentialsShared($guest, $owner, [$credential]);
    }

    public function testNotifyShareRevokedCreatesNotification(): void
    {
        $owner = $this->createUser(1, 'owner@example.test');
        $guest = $this->createUser(2, 'guest@example.test');
        $credential = $this->createCredential(10, $owner, 'Github');

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router
            ->expects($this->once())
            ->method('generate')
            ->with('shared_access_index')
            ->willReturn('/app/shared-access');

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService
            ->expects($this->once())
            ->method('createEntityNotification')
            ->with(
                $this->identicalTo($guest),
                'Shared access revoked',
                $this->identicalTo($credential),
                $this->stringContains('revoked access'),
                'info',
                '/app/shared-access',
                'fa-solid fa-share-nodes',
                'normal'
            )
            ->willReturn($this->createStub(\App\Entity\Notification::class));

        $notifier = new SharedAccessNotifier($notificationService, $router, $this->createTranslator());

        $notifier->notifyShareRevoked($guest, $owner, $credential);
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

    private function createCredential(int $id, User $owner, string $name): Credential
    {
        $credential = (new Credential())
            ->setUser($owner)
            ->setName($name)
            ->setDomain(strtolower($name) . '.com')
            ->setUsername('owner')
            ->setPassword('encrypted');

        $reflection = new \ReflectionProperty(Credential::class, 'id');
        $reflection->setValue($credential, $id);

        return $credential;
    }

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnCallback(function (string $id, array $parameters = []) {
                $catalog = [
                    'app_notifications.shared_access.shared_single_title' => 'Credential shared with you',
                    'app_notifications.shared_access.shared_single_message' => '%owner% shared "%credential%" with you.',
                    'app_notifications.shared_access.revoked_title' => 'Shared access revoked',
                    'app_notifications.shared_access.revoked_message' => '%owner% revoked access to "%credential%".',
                ];

                return strtr($catalog[$id] ?? $id, $parameters);
            });

        return $translator;
    }
}
