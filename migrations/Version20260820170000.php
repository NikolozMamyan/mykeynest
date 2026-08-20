<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260820170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the user preference controlling email two-factor authentication';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();

        if ($platform === 'mysql') {
            $this->addSql('ALTER TABLE user ADD email_two_factor_enabled TINYINT(1) DEFAULT 1 NOT NULL');

            return;
        }

        if ($platform === 'postgresql') {
            $this->addSql('ALTER TABLE "user" ADD email_two_factor_enabled BOOLEAN DEFAULT TRUE NOT NULL');

            return;
        }

        $this->addSql('ALTER TABLE user ADD email_two_factor_enabled BOOLEAN DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform()->getName() === 'postgresql') {
            $this->addSql('ALTER TABLE "user" DROP email_two_factor_enabled');

            return;
        }

        $this->addSql('ALTER TABLE user DROP email_two_factor_enabled');
    }
}
