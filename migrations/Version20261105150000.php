<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional shared-access validity, expiry reminders and extension requests; preserve existing unlimited shares.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('shared_access');
        foreach (['expires_at', 'owner_reminder_sent_at', 'guest_reminder_sent_at', 'extension_requested_at'] as $column) {
            $table->addColumn($column, Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        }
        $table->addColumn('unlimited_on_activation', Types::BOOLEAN, ['default' => false]);
        $table->addIndex(['expires_at'], 'IDX_SHARED_ACCESS_EXPIRY');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('shared_access');
        $table->dropIndex('IDX_SHARED_ACCESS_EXPIRY');
        foreach (['expires_at', 'owner_reminder_sent_at', 'guest_reminder_sent_at', 'extension_requested_at', 'unlimited_on_activation'] as $column) {
            $table->dropColumn($column);
        }
    }
}
