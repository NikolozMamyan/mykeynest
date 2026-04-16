<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260416113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add persistent credential encryption key decoupled from the extension API token';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD credential_encryption_key VARCHAR(64) DEFAULT NULL');
        $this->addSql('UPDATE user SET credential_encryption_key = api_extension_token WHERE credential_encryption_key IS NULL AND api_extension_token IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP credential_encryption_key');
    }
}
