<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remember the product guide for new accounts; existing accounts keep a null state';
    }

    public function up(Schema $schema): void
    {
        $table = $this->connection->getDatabasePlatform()->getName() === 'postgresql' ? '"user"' : 'user';
        $this->addSql('ALTER TABLE '.$table.' ADD product_guide_state JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $table = $this->connection->getDatabasePlatform()->getName() === 'postgresql' ? '"user"' : 'user';
        $this->addSql('ALTER TABLE '.$table.' DROP product_guide_state');
    }
}
