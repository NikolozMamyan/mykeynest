<?php

declare(strict_types=1);

namespace App\Tests\Service;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261029100000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2).'/migrations/Version20261029100000.php';

final class GoogleRegistrationMigrationTest extends TestCase
{
    public function testPreservesExistingAccountsAndAllowsPersonalAndGoogleRegistrations(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new Schema();
        $user = $schema->createTable('user');
        $user->addColumn('id', 'integer');
        $user->setPrimaryKey(['id']);
        $user->addColumn('company', 'string', ['length' => 255]);
        $user->addColumn('password', 'string', ['length' => 255]);
        foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
            $connection->executeStatement($sql);
        }
        $connection->insert('user', ['id' => 1, 'company' => 'Existing company', 'password' => 'existing-hash']);
        $updated = clone $schema;
        (new Version20261029100000($connection, new NullLogger()))->up($updated);
        $manager = $connection->createSchemaManager();
        foreach ($connection->getDatabasePlatform()->getAlterSchemaSQL($manager->createComparator()->compareSchemas($schema, $updated)) as $sql) {
            $connection->executeStatement($sql);
        }
        self::assertSame('existing-hash', $connection->fetchOne('SELECT password FROM user WHERE id = 1'));
        $connection->insert('user', ['id' => 2, 'company' => null, 'password' => null, 'google_subject' => 'google-subject']);
        $connection->insert('user', ['id' => 3, 'company' => null, 'password' => 'hash', 'google_subject' => null]);
        $connection->insert('user', ['id' => 4, 'company' => 'Company', 'password' => 'hash', 'google_subject' => null]);
        self::assertSame(4, (int) $connection->fetchOne('SELECT COUNT(*) FROM user'));
        self::expectException(UniqueConstraintViolationException::class);
        $connection->insert('user', ['id' => 5, 'google_subject' => 'google-subject']);
    }
}
