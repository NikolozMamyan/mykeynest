<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260417173000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pin_position to credential for ordered pinned credentials';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE credential ADD pin_position INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE credential DROP pin_position');
    }
}
