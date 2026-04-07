<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260407133000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill user_subscription one last time and drop legacy subscription columns from user';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();

        if ($platform === 'mysql') {
            $this->addSql('INSERT INTO user_subscription (user_id, stripe_customer_id, stripe_subscription_id, status, plan_code, is_active, created_at, updated_at)
                SELECT u.id, u.stripe_customer_id, u.stripe_subscription_id, CASE WHEN u.is_subscribed = 1 THEN \'active\' ELSE NULL END, \'pro\', u.is_subscribed, NOW(), NOW()
                FROM user u
                WHERE (u.stripe_customer_id IS NOT NULL OR u.stripe_subscription_id IS NOT NULL OR u.is_subscribed = 1)
                AND NOT EXISTS (
                    SELECT 1 FROM user_subscription us WHERE us.user_id = u.id
                )');
            $this->addSql('UPDATE user_subscription us
                INNER JOIN user u ON u.id = us.user_id
                SET us.stripe_customer_id = COALESCE(us.stripe_customer_id, u.stripe_customer_id),
                    us.stripe_subscription_id = COALESCE(us.stripe_subscription_id, u.stripe_subscription_id),
                    us.status = COALESCE(us.status, CASE WHEN u.is_subscribed = 1 THEN \'active\' ELSE us.status END),
                    us.plan_code = COALESCE(us.plan_code, \'pro\'),
                    us.is_active = CASE WHEN us.is_active = 1 OR u.is_subscribed = 1 THEN 1 ELSE 0 END,
                    us.updated_at = NOW()');
            $this->addSql('ALTER TABLE user DROP COLUMN is_subscribed, DROP COLUMN stripe_customer_id, DROP COLUMN stripe_subscription_id');

            return;
        }

        if ($platform === 'postgresql') {
            $this->addSql('INSERT INTO user_subscription (user_id, stripe_customer_id, stripe_subscription_id, status, plan_code, is_active, created_at, updated_at)
                SELECT u.id, u.stripe_customer_id, u.stripe_subscription_id, CASE WHEN u.is_subscribed = TRUE THEN \'active\' ELSE NULL END, \'pro\', u.is_subscribed, NOW(), NOW()
                FROM "user" u
                WHERE (u.stripe_customer_id IS NOT NULL OR u.stripe_subscription_id IS NOT NULL OR u.is_subscribed = TRUE)
                AND NOT EXISTS (
                    SELECT 1 FROM user_subscription us WHERE us.user_id = u.id
                )');
            $this->addSql('UPDATE user_subscription us
                SET stripe_customer_id = COALESCE(us.stripe_customer_id, u.stripe_customer_id),
                    stripe_subscription_id = COALESCE(us.stripe_subscription_id, u.stripe_subscription_id),
                    status = COALESCE(us.status, CASE WHEN u.is_subscribed = TRUE THEN \'active\' ELSE us.status END),
                    plan_code = COALESCE(us.plan_code, \'pro\'),
                    is_active = CASE WHEN us.is_active = TRUE OR u.is_subscribed = TRUE THEN TRUE ELSE FALSE END,
                    updated_at = NOW()
                FROM "user" u
                WHERE u.id = us.user_id');
            $this->addSql('ALTER TABLE "user" DROP COLUMN is_subscribed, DROP COLUMN stripe_customer_id, DROP COLUMN stripe_subscription_id');

            return;
        }

        $this->abortIf(true, sprintf('Unsupported platform: %s', $platform));
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();

        if ($platform === 'mysql') {
            $this->addSql('ALTER TABLE user ADD is_subscribed TINYINT(1) DEFAULT 0 NOT NULL, ADD stripe_customer_id VARCHAR(255) DEFAULT NULL, ADD stripe_subscription_id VARCHAR(255) DEFAULT NULL');
            $this->addSql('UPDATE user u
                LEFT JOIN user_subscription us ON us.user_id = u.id
                SET u.is_subscribed = COALESCE(us.is_active, 0),
                    u.stripe_customer_id = us.stripe_customer_id,
                    u.stripe_subscription_id = us.stripe_subscription_id');

            return;
        }

        if ($platform === 'postgresql') {
            $this->addSql('ALTER TABLE "user" ADD is_subscribed BOOLEAN DEFAULT FALSE NOT NULL, ADD stripe_customer_id VARCHAR(255) DEFAULT NULL, ADD stripe_subscription_id VARCHAR(255) DEFAULT NULL');
            $this->addSql('UPDATE "user" u
                SET is_subscribed = COALESCE(us.is_active, FALSE),
                    stripe_customer_id = us.stripe_customer_id,
                    stripe_subscription_id = us.stripe_subscription_id
                FROM user_subscription us
                WHERE us.user_id = u.id');

            return;
        }

        $this->abortIf(true, sprintf('Unsupported platform: %s', $platform));
    }
}
