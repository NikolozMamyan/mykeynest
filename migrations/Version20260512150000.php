<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add support chat conversations and messages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE support_conversation (id INT AUTO_INCREMENT NOT NULL, public_token VARCHAR(64) NOT NULL, email VARCHAR(180) NOT NULL, status VARCHAR(40) NOT NULL, unread_for_admin TINYINT(1) DEFAULT 0 NOT NULL, unread_for_visitor TINYINT(1) DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', last_message_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_7744D0C6A59A6A1 (public_token), INDEX IDX_7744D0C6E7927C74 (email), INDEX IDX_7744D0C69E0B3D32 (last_message_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE support_message (id INT AUTO_INCREMENT NOT NULL, conversation_id INT NOT NULL, author_type VARCHAR(40) NOT NULL, author_email VARCHAR(180) DEFAULT NULL, body LONGTEXT NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_6A8A55B79AC2C3B2 (conversation_id), INDEX IDX_6A8A55B7D36E9C11 (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE support_message ADD CONSTRAINT FK_6A8A55B79AC2C3B2 FOREIGN KEY (conversation_id) REFERENCES support_conversation (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE support_message DROP FOREIGN KEY FK_6A8A55B79AC2C3B2');
        $this->addSql('DROP TABLE support_message');
        $this->addSql('DROP TABLE support_conversation');
    }
}
