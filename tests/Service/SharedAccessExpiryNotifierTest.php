<?php

namespace App\Tests\Service;

use App\Entity\Credential;
use App\Entity\Notification;
use App\Entity\SharedAccess;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Repository\SharedAccessRepository;
use App\Service\MailerService;
use App\Service\NotificationService;
use App\Service\SharedAccessExpiryNotifier;
use App\Service\SharedAccessValidityManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SharedAccessExpiryNotifierTest extends TestCase
{
    public function testBothRecipientsAreAlertedOnlyOnceForEachDeadline(): void
    {
        $share = $this->share('+12 hours');
        $mailer = $this->createMock(MailerService::class);
        $mailer->expects(self::exactly(4))->method('send');
        $notifications = $this->createMock(NotificationService::class);
        $notifications->expects(self::exactly(2))->method('createNotification')->willReturn(new Notification());
        $notifier = $this->notifier([$share], $mailer, $notifications);
        self::assertSame(2, $notifier->sendDue());
        self::assertSame(0, $notifier->sendDue());
        $share->setValidity(new \DateTimeImmutable('+18 hours'));
        self::assertSame(2, $notifier->sendDue());
    }

    public function testExpiredUnlimitedAndDistantSharesDoNotSendReminders(): void
    {
        $unlimited = $this->share('+1 day')->setValidity(null);
        $mailer = $this->createMock(MailerService::class);
        $mailer->expects(self::never())->method('send');
        self::assertSame(0, $this->notifier([$unlimited, $this->share('-1 minute'), $this->share('+3 days')], $mailer)->sendDue());
    }

    public function testFailedRecipientCanBeRetriedWithoutAlertingSuccessfulRecipientAgain(): void
    {
        $share = $this->share('+6 hours');
        $calls = [];
        $mailer = $this->createMock(MailerService::class);
        $mailer->method('send')->willReturnCallback(static function (string $email) use (&$calls): void {
            $calls[] = $email;
            if (count($calls) === 1) throw new \RuntimeException('Temporary transport failure');
        });
        $notifications = $this->createMock(NotificationService::class);
        $notifications->expects(self::once())->method('createNotification')->willReturn(new Notification());
        $repository = $this->createMock(NotificationRepository::class);
        $repository->method('findOneBy')->willReturnOnConsecutiveCalls(null, new Notification());
        $notifier = $this->notifier([$share], $mailer, $notifications, $repository);
        self::assertSame(1, $notifier->sendDue());
        self::assertNull($share->getOwnerReminderSentAt());
        self::assertNotNull($share->getGuestReminderSentAt());
        self::assertSame(1, $notifier->sendDue());
        self::assertSame(['owner@example.com', 'guest@example.com', 'owner@example.com'], $calls);
    }

    private function share(string $when): SharedAccess
    {
        $share = (new SharedAccess())->setOwner((new User())->setEmail('owner@example.com'))->setGuest((new User())->setEmail('guest@example.com')->setRoles(['ROLE_GUEST'])->setApiToken('invitation'))
            ->setCredential((new Credential())->setName('Figma'))->setValidity(new \DateTimeImmutable($when), true);
        (new \ReflectionProperty(SharedAccess::class, 'id'))->setValue($share, 10);

        return $share;
    }

    private function notifier(array $items, MailerService $mailer, ?NotificationService $notifications = null, ?NotificationRepository $repository = null): SharedAccessExpiryNotifier
    {
        $shares = $this->createMock(SharedAccessRepository::class);
        $shares->method('findDueForExpiryReminder')->willReturn($items);
        $em = $this->createMock(EntityManagerInterface::class);
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('https://key-nest.com/preview');
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key) => $key);
        $notifications ??= $this->createMock(NotificationService::class);
        $validity = new SharedAccessValidityManager($shares, $em, $notifications, $urls, $translator, 'test-secret');

        return new SharedAccessExpiryNotifier($shares, $repository ?? $this->createMock(NotificationRepository::class), $notifications, $validity, $mailer, $urls, $translator, $em, new NullLogger());
    }
}
