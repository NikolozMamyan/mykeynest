<?php

namespace App\Tests\Service;

use App\Entity\Credential;
use App\Entity\Notification;
use App\Entity\SharedAccess;
use App\Entity\User;
use App\Repository\SharedAccessRepository;
use App\Service\NotificationService;
use App\Service\SharedAccessValidityManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SharedAccessValidityManagerTest extends TestCase
{
    public function testExistingAccountsHaveUnlimitedAccessUnlessADateWasChosen(): void
    {
        $share = $this->share(false);
        $manager = $this->manager();
        $manager->apply($share, null);
        self::assertNull($share->getExpiresAt());
        $date = new \DateTimeImmutable('+3 days');
        $manager->apply($share, $date);
        self::assertSame($date, $share->getExpiresAt());
        self::assertFalse($share->isUnlimitedOnActivation());
    }

    public function testGuestHasSevenDaysAndKeepsExistingInvitationLinks(): void
    {
        $share = $this->share(true);
        $guest = $share->getGuest()->setApiToken('existing-link')->setTokenExpiresAt(new \DateTimeImmutable('+30 days'));
        $before = new \DateTimeImmutable('+7 days');
        $this->manager()->apply($share, null);
        self::assertGreaterThanOrEqual($before, $share->getExpiresAt());
        self::assertLessThan(new \DateTimeImmutable('+7 days +1 minute'), $share->getExpiresAt());
        self::assertTrue($share->isUnlimitedOnActivation());
        self::assertSame('existing-link', $guest->getApiToken());
        self::assertGreaterThan(new \DateTimeImmutable('+29 days'), $guest->getTokenExpiresAt());
    }

    public function testActivationOnlyClearsUnexpiredDefaultDates(): void
    {
        $guest = $this->share(true)->getGuest();
        $default = (new SharedAccess())->setGuest($guest)->setValidity(new \DateTimeImmutable('+1 day'), true);
        $custom = (new SharedAccess())->setGuest($guest)->setValidity(new \DateTimeImmutable('+1 day'));
        $expired = (new SharedAccess())->setGuest($guest)->setValidity(new \DateTimeImmutable('-1 day'), true);
        $shares = $this->createMock(SharedAccessRepository::class);
        $shares->method('findBy')->with(['guest' => $guest])->willReturn([$default, $custom, $expired]);
        $this->manager($shares)->activate($guest);
        self::assertNull($default->getExpiresAt());
        self::assertNotNull($custom->getExpiresAt());
        self::assertTrue($expired->isExpired());
    }

    public function testExplicitDateExtendsTheInvitationButNeverBecomesUnlimitedOnActivation(): void
    {
        $share = $this->share(true);
        $date = new \DateTimeImmutable('+20 days');
        $this->manager()->apply($share, $date);
        self::assertSame($date, $share->getGuest()->getTokenExpiresAt());
        self::assertNotNull($share->getGuest()->getApiToken());
        self::assertFalse($share->isUnlimitedOnActivation());
    }

    /** @dataProvider invalidDates */
    public function testInvalidOrPastDatesAreRejected(string $value): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('share_validity.invalid_date');
        $this->manager()->parseExpiry($value);
    }

    public static function invalidDates(): array
    {
        return [['not-a-date'], ['2020-01-01T12:00'], ['2099-02-31T10:00'], ['2099-01-01T27:00']];
    }

    public function testDatesUseParisTimeAndResetRemindersOnlyWhenChanged(): void
    {
        $manager = $this->manager();
        self::assertNull($manager->parseExpiry(''));
        $date = $manager->parseExpiry('2099-01-01T12:30');
        self::assertSame('12:30', $date->setTimezone(new \DateTimeZone('Europe/Paris'))->format('H:i'));
        self::assertSame('11:30', $date->setTimezone(new \DateTimeZone('UTC'))->format('H:i'));
        $share = $this->share(false)->setValidity($date)->setOwnerReminderSentAt(new \DateTimeImmutable())->setGuestReminderSentAt(new \DateTimeImmutable())->setExtensionRequestedAt(new \DateTimeImmutable());
        $manager->apply($share, $date);
        self::assertNotNull($share->getOwnerReminderSentAt());
        $share->setValidity($date, true);
        self::assertNotNull($share->getExtensionRequestedAt());
        $manager->apply($share, new \DateTimeImmutable('+2 days'));
        self::assertNull($share->getOwnerReminderSentAt());
        self::assertNull($share->getGuestReminderSentAt());
        self::assertNull($share->getExtensionRequestedAt());
    }

    public function testSignedRequestsNotifyOwnerOnlyOnceAndBecomeInvalidAfterRenewal(): void
    {
        $share = $this->share(true)->setValidity(new \DateTimeImmutable('+1 day'));
        $em = $this->createMock(EntityManagerInterface::class);
        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->onlyMethods(['beginTransaction', 'commit', 'rollBack'])->getMock();
        $em->method('getConnection')->willReturn($connection);
        $notifications = $this->createMock(NotificationService::class);
        $notifications->expects(self::once())->method('createNotification')->with($share->getOwner(), self::anything(), self::stringContains('guest@example.com'), self::anything(), self::anything(), self::anything(), self::anything(), self::stringContains('share-extension-10-'))->willReturn(new Notification());
        $manager = $this->manager(null, $em, $notifications);
        parse_str(parse_url($manager->extensionRequestUrl($share), PHP_URL_QUERY), $parameters);
        self::assertFalse($manager->acceptsSignature($share, 'invalid'));
        self::assertTrue($manager->requestExtension($share, $parameters['signature']));
        self::assertFalse($manager->requestExtension($share, $parameters['signature']));
        $share->setValidity(new \DateTimeImmutable('+2 days'));
        self::assertFalse($manager->acceptsSignature($share, $parameters['signature']));
    }

    private function share(bool $guest): SharedAccess
    {
        $user = (new User())->setEmail('guest@example.com')->setRoles($guest ? ['ROLE_GUEST'] : ['ROLE_USER']);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 2);
        $share = (new SharedAccess())->setOwner((new User())->setEmail('owner@example.com'))->setGuest($user)->setCredential((new Credential())->setName('Figma'));
        (new \ReflectionProperty(SharedAccess::class, 'id'))->setValue($share, 10);

        return $share;
    }

    private function manager(?SharedAccessRepository $shares = null, ?EntityManagerInterface $em = null, ?NotificationService $notifications = null): SharedAccessValidityManager
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $params = []) => 'https://key-nest.com/'.$route.'?'.http_build_query($params));
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key, array $params = []) => $key.' '.implode(' ', $params));

        return new SharedAccessValidityManager($shares ?? $this->createMock(SharedAccessRepository::class), $em ?? $this->createMock(EntityManagerInterface::class), $notifications ?? $this->createMock(NotificationService::class), $urls, $translator, 'test-secret');
    }
}
