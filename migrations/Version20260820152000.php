<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260820152000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scope Stripe webhook idempotency to Sandbox or Production mode';
    }

    public function up(Schema $schema): void
    {
        $this->dropWebhookIndex();
        $this->addSql('CREATE UNIQUE INDEX uniq_stripe_webhook_event_id ON stripe_webhook_event (stripe_event_id, stripe_mode)');
    }

    public function down(Schema $schema): void
    {
        $this->dropWebhookIndex();
        $this->addSql('CREATE UNIQUE INDEX uniq_stripe_webhook_event_id ON stripe_webhook_event (stripe_event_id)');
    }

    private function dropWebhookIndex(): void
    {
        if ($this->connection->getDatabasePlatform()->getName() === 'mysql') {
            $this->addSql('DROP INDEX uniq_stripe_webhook_event_id ON stripe_webhook_event');

            return;
        }

        $this->addSql('DROP INDEX uniq_stripe_webhook_event_id');
    }
}
