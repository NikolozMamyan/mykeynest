<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260907110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add an optional sign-in URL to credentials';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE credential ADD login_url VARCHAR(2048) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE credential DROP login_url');
    }
}
