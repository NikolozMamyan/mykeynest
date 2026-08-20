<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260820151000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Guarantee a single Stripe payment mode configuration row';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE stripe_payment_configuration ADD configuration_key VARCHAR(50) DEFAULT 'stripe' NOT NULL");
        $this->addSql('CREATE UNIQUE INDEX uniq_stripe_payment_configuration_key ON stripe_payment_configuration (configuration_key)');
    }

    public function down(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform()->getName() === 'mysql') {
            $this->addSql('DROP INDEX uniq_stripe_payment_configuration_key ON stripe_payment_configuration');
        } else {
            $this->addSql('DROP INDEX uniq_stripe_payment_configuration_key');
        }
        $this->addSql('ALTER TABLE stripe_payment_configuration DROP configuration_key');
    }
}
