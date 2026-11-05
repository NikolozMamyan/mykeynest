<?php

namespace App\Tests\Repository;

use App\Entity\Credential;
use App\Entity\SharedAccess;
use App\Entity\User;
use App\Repository\SharedAccessRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

final class SharedAccessRepositoryTest extends TestCase
{
    public function testExpiredSharesDisappearFromListsPermissionsAndReminderSelection(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite is required.');
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2).'/src/Entity'], true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $em = new EntityManager($connection, $config);
        (new SchemaTool($em))->createSchema(array_map($em->getClassMetadata(...), [User::class, Credential::class, SharedAccess::class]));
        $owner = (new User())->setEmail('owner@example.com')->setCompany('Studio')->setPassword('hash');
        $guest = (new User())->setEmail('guest@example.com')->setCompany('Studio')->setPassword('hash');
        $em->persist($owner);
        $em->persist($guest);
        $items = [];
        foreach (['unlimited' => null, 'active' => '+12 hours', 'expired' => '-1 minute', 'distant' => '+7 days'] as $name => $when) {
            $credential = (new Credential())->setName($name)->setDomain('example.com')->setUsername('user')->setPassword('encrypted')->setUser($owner);
            $share = (new SharedAccess())->setOwner($owner)->setGuest($guest)->setCredential($credential)->setCreatedAt(new \DateTimeImmutable())->setCanRevealPassword(true)
                ->setValidity($when ? new \DateTimeImmutable($when) : null);
            $em->persist($credential);
            $em->persist($share);
            $items[$name] = $share;
        }
        $em->flush();
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $repository = new SharedAccessRepository($registry);
        self::assertCount(3, $repository->findSharedWith($guest));
        self::assertFalse($repository->userHasAccessToCredential($guest, $items['expired']->getCredential()));
        self::assertFalse($repository->userCanRevealPassword($guest, $items['expired']->getCredential()));
        self::assertTrue($repository->userCanRevealPassword($guest, $items['unlimited']->getCredential()));
        self::assertSame([$items['active']], $repository->findDueForExpiryReminder(new \DateTimeImmutable()));
        $items['active']->setOwnerReminderSentAt(new \DateTimeImmutable())->setGuestReminderSentAt(new \DateTimeImmutable());
        $em->flush();
        self::assertSame([], $repository->findDueForExpiryReminder(new \DateTimeImmutable()));
        $em->close();
        $connection->close();
    }
}
