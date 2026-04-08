<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260408110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create extension installation challenge table for team second-install approval flow';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE extension_installation_challenge (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, public_id VARCHAR(36) NOT NULL, token_hash VARCHAR(64) NOT NULL, requested_client_id VARCHAR(128) NOT NULL, device_label VARCHAR(255) DEFAULT NULL, browser_name VARCHAR(100) DEFAULT NULL, browser_version VARCHAR(50) DEFAULT NULL, os_name VARCHAR(100) DEFAULT NULL, os_version VARCHAR(50) DEFAULT NULL, extension_version VARCHAR(50) DEFAULT NULL, manifest_version VARCHAR(20) DEFAULT NULL, origin_type VARCHAR(20) DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(1000) DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', approved_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', rejected_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', completed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_EXT_INSTALL_CHALLENGE_PUBLIC_ID (public_id), UNIQUE INDEX UNIQ_EXT_INSTALL_CHALLENGE_TOKEN_HASH (token_hash), INDEX IDX_EXT_INSTALL_CHALLENGE_USER (user_id), INDEX idx_ext_install_challenge_token_hash (token_hash), INDEX idx_ext_install_challenge_public_id (public_id), INDEX idx_ext_install_challenge_client_id (requested_client_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE extension_installation_challenge ADD CONSTRAINT FK_EXT_INSTALL_CHALLENGE_USER FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE extension_installation_challenge DROP FOREIGN KEY FK_EXT_INSTALL_CHALLENGE_USER');
        $this->addSql('DROP TABLE extension_installation_challenge');
    }
}
