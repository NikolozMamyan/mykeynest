<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track public visitor sessions, countries, acquisition sources and page views';
    }

    public function up(Schema $schema): void
    {
        $session = $schema->createTable('visitor_session');
        $session->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        foreach (['visitor_key' => 64, 'ip_address' => 45, 'user_agent' => 500, 'entry_path' => 1000, 'current_path' => 1000, 'source' => 200] as $name => $length) {
            $session->addColumn($name, Types::STRING, ['length' => $length]);
        }
        foreach (['country_code' => 2, 'medium' => 100, 'campaign' => 200] as $name => $length) {
            $session->addColumn($name, Types::STRING, ['length' => $length, 'notnull' => false]);
        }
        $session->addColumn('started_at', Types::DATETIME_IMMUTABLE);
        $session->addColumn('last_seen_at', Types::DATETIME_IMMUTABLE);
        $session->addColumn('page_view_count', Types::INTEGER);
        $session->setPrimaryKey(['id']);
        $session->addIndex(['visitor_key', 'last_seen_at'], 'idx_visitor_key_activity');
        $session->addIndex(['last_seen_at'], 'idx_visitor_activity');
        $session->addIndex(['started_at'], 'idx_visitor_started');
        $session->addIndex(['ip_address'], 'idx_visitor_ip');

        $visit = $schema->createTable('page_visit');
        $visit->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $visit->addColumn('session_id', Types::INTEGER);
        $visit->addColumn('path', Types::STRING, ['length' => 1000]);
        $visit->addColumn('visited_at', Types::DATETIME_IMMUTABLE);
        $visit->setPrimaryKey(['id']);
        $visit->addIndex(['session_id', 'visited_at'], 'idx_page_visit_session');
        $visit->addIndex(['visited_at'], 'idx_page_visit_date');
        $visit->addForeignKeyConstraint($session, ['session_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('page_visit');
        $schema->dropTable('visitor_session');
    }
}
