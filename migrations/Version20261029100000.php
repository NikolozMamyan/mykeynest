<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow personal accounts without a company and add Google sign-in identities';
    }

    public function up(Schema $schema): void
    {
        $user = $schema->getTable('user');
        $user->changeColumn('company', ['notnull' => false]);
        $user->changeColumn('password', ['notnull' => false]);
        $user->addColumn('google_subject', Types::STRING, ['length' => 255, 'notnull' => false]);
        $user->addUniqueIndex(['google_subject'], 'UNIQ_USER_GOOGLE_SUBJECT');
    }

    public function down(Schema $schema): void
    {
        $table = $this->connection->quoteIdentifier('user');
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE company IS NULL OR password IS NULL') > 0,
            'Personal or Google accounts must be migrated before reverting this schema.');
        $user = $schema->getTable('user');
        $user->dropIndex('UNIQ_USER_GOOGLE_SUBJECT');
        $user->dropColumn('google_subject');
        $user->changeColumn('company', ['notnull' => true]);
        $user->changeColumn('password', ['notnull' => true]);
    }
}
