<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260417150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create email_campaign table for admin emailing history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE email_campaign (id INT AUTO_INCREMENT NOT NULL, subject VARCHAR(255) NOT NULL, html_content LONGTEXT NOT NULL, recipients JSON NOT NULL, failed_recipients JSON NOT NULL, recipient_count INT NOT NULL, failed_count INT NOT NULL, sent_by_email VARCHAR(180) DEFAULT NULL, sent_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE email_campaign');
    }
}
