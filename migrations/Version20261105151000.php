<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105151000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve existing guest invitation deadlines as per-share validity; leave active-account shares unlimited.';
    }

    public function up(Schema $schema): void
    {
        $users = $this->connection->quoteIdentifier('user');
        $rows = $this->connection->fetchAllAssociative('SELECT s.id, u.roles, u.token_expires_at FROM shared_access s JOIN '.$users.' u ON s.guest_id = u.id WHERE s.expires_at IS NULL');
        foreach ($rows as $row) {
            if (!in_array('ROLE_GUEST', json_decode($row['roles'], true, 512, JSON_THROW_ON_ERROR), true)) continue;
            $this->addSql('UPDATE shared_access SET expires_at = ?, unlimited_on_activation = ? WHERE id = ? AND expires_at IS NULL', [
                $row['token_expires_at'] ?? (new \DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s'), true, (int) $row['id'],
            ], [Types::STRING, Types::BOOLEAN, Types::INTEGER]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Historical guest deadlines must be retained to avoid reopening expired access.');
    }
}
