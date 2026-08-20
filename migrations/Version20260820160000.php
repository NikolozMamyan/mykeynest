<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260820160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add company workspaces and seat memberships for Stripe Team subscriptions';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();

        if ($platform === 'mysql') {
            $this->addSql("CREATE TABLE company_organization (id INT AUTO_INCREMENT NOT NULL, owner_id INT NOT NULL, name VARCHAR(180) NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_3F23A9B27E3C61F9 (owner_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            $this->addSql("CREATE TABLE organization_member (id INT AUTO_INCREMENT NOT NULL, organization_id INT NOT NULL, user_id INT NOT NULL, invited_by_id INT DEFAULT NULL, role VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, invited_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', invitation_expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', joined_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_756A2A8D32C8A3DE (organization_id), INDEX IDX_756A2A8DA76ED395 (user_id), INDEX IDX_756A2A8DA7B4A7E3 (invited_by_id), UNIQUE INDEX uniq_organization_member_user (organization_id, user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            $this->addSql('ALTER TABLE company_organization ADD CONSTRAINT FK_3F23A9B27E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE RESTRICT');
            $this->addSql('ALTER TABLE organization_member ADD CONSTRAINT FK_756A2A8D32C8A3DE FOREIGN KEY (organization_id) REFERENCES company_organization (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE organization_member ADD CONSTRAINT FK_756A2A8DA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE organization_member ADD CONSTRAINT FK_756A2A8DA7B4A7E3 FOREIGN KEY (invited_by_id) REFERENCES user (id) ON DELETE SET NULL');
            $this->addSql('ALTER TABLE team ADD organization_id INT DEFAULT NULL, ADD CONSTRAINT FK_C4E0A61F32C8A3DE FOREIGN KEY (organization_id) REFERENCES company_organization (id) ON DELETE SET NULL');
            $this->addSql('CREATE INDEX IDX_C4E0A61F32C8A3DE ON team (organization_id)');
            $this->addSql('ALTER TABLE user_subscription ADD organization_id INT DEFAULT NULL, ADD CONSTRAINT FK_230A18D132C8A3DE FOREIGN KEY (organization_id) REFERENCES company_organization (id) ON DELETE SET NULL');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_230A18D132C8A3DE ON user_subscription (organization_id)');
            $this->addSql("UPDATE user_subscription SET quantity = 6 WHERE LOWER(COALESCE(plan_code, '')) = 'team' AND stripe_price_id IS NULL AND quantity < 6");
            $this->addSql("INSERT INTO company_organization (owner_id, name, status, created_at, updated_at) SELECT us.user_id, LEFT(CASE WHEN TRIM(COALESCE(u.company, '')) <> '' THEN u.company ELSE CONCAT('Entreprise ', SUBSTRING_INDEX(u.email, '@', 1)) END, 180), 'ACTIVE', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM user_subscription us INNER JOIN user u ON u.id = us.user_id WHERE LOWER(COALESCE(us.plan_code, '')) = 'team' AND us.is_active = 1 AND NOT EXISTS (SELECT 1 FROM company_organization existing WHERE existing.owner_id = us.user_id)");
            $this->addSql("UPDATE user_subscription us INNER JOIN company_organization organization ON organization.owner_id = us.user_id SET us.organization_id = organization.id WHERE LOWER(COALESCE(us.plan_code, '')) = 'team'");
            $this->addSql("INSERT IGNORE INTO organization_member (organization_id, user_id, invited_by_id, role, status, invited_at, invitation_expires_at, joined_at) SELECT organization.id, organization.owner_id, NULL, 'OWNER', 'ACTIVE', CURRENT_TIMESTAMP, NULL, CURRENT_TIMESTAMP FROM company_organization organization");
            $this->addSql("INSERT IGNORE INTO organization_member (organization_id, user_id, invited_by_id, role, status, invited_at, invitation_expires_at, joined_at) SELECT DISTINCT organization.id, member.user_id, organization.owner_id, CASE WHEN CAST(member_user.roles AS CHAR) LIKE '%ROLE_GUEST%' THEN 'GUEST' ELSE 'MEMBER' END, CASE WHEN CAST(member_user.roles AS CHAR) LIKE '%ROLE_GUEST%' THEN 'PENDING' ELSE 'ACTIVE' END, CURRENT_TIMESTAMP, CASE WHEN CAST(member_user.roles AS CHAR) LIKE '%ROLE_GUEST%' THEN member_user.token_expires_at ELSE NULL END, CASE WHEN CAST(member_user.roles AS CHAR) LIKE '%ROLE_GUEST%' THEN NULL ELSE CURRENT_TIMESTAMP END FROM team_member member INNER JOIN team team_record ON team_record.id = member.team_id INNER JOIN company_organization organization ON organization.owner_id = team_record.owner_id INNER JOIN user member_user ON member_user.id = member.user_id");
            $this->addSql('UPDATE team team_record INNER JOIN company_organization organization ON organization.owner_id = team_record.owner_id SET team_record.organization_id = organization.id WHERE team_record.organization_id IS NULL');

            return;
        }

        if ($platform === 'postgresql') {
            $this->addSql("CREATE TABLE company_organization (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, owner_id INT NOT NULL, name VARCHAR(180) NOT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
            $this->addSql("COMMENT ON COLUMN company_organization.created_at IS '(DC2Type:datetime_immutable)'");
            $this->addSql("COMMENT ON COLUMN company_organization.updated_at IS '(DC2Type:datetime_immutable)'");
            $this->addSql('CREATE INDEX IDX_3F23A9B27E3C61F9 ON company_organization (owner_id)');
            $this->addSql("CREATE TABLE organization_member (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, organization_id INT NOT NULL, user_id INT NOT NULL, invited_by_id INT DEFAULT NULL, role VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, invited_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, invitation_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, joined_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))");
            $this->addSql("COMMENT ON COLUMN organization_member.invited_at IS '(DC2Type:datetime_immutable)'");
            $this->addSql("COMMENT ON COLUMN organization_member.invitation_expires_at IS '(DC2Type:datetime_immutable)'");
            $this->addSql("COMMENT ON COLUMN organization_member.joined_at IS '(DC2Type:datetime_immutable)'");
            $this->addSql('CREATE INDEX IDX_756A2A8D32C8A3DE ON organization_member (organization_id)');
            $this->addSql('CREATE INDEX IDX_756A2A8DA76ED395 ON organization_member (user_id)');
            $this->addSql('CREATE INDEX IDX_756A2A8DA7B4A7E3 ON organization_member (invited_by_id)');
            $this->addSql('CREATE UNIQUE INDEX uniq_organization_member_user ON organization_member (organization_id, user_id)');
            $this->addSql('ALTER TABLE company_organization ADD CONSTRAINT FK_3F23A9B27E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE organization_member ADD CONSTRAINT FK_756A2A8D32C8A3DE FOREIGN KEY (organization_id) REFERENCES company_organization (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE organization_member ADD CONSTRAINT FK_756A2A8DA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE organization_member ADD CONSTRAINT FK_756A2A8DA7B4A7E3 FOREIGN KEY (invited_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE team ADD organization_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE team ADD CONSTRAINT FK_C4E0A61F32C8A3DE FOREIGN KEY (organization_id) REFERENCES company_organization (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('CREATE INDEX IDX_C4E0A61F32C8A3DE ON team (organization_id)');
            $this->addSql('ALTER TABLE user_subscription ADD organization_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE user_subscription ADD CONSTRAINT FK_230A18D132C8A3DE FOREIGN KEY (organization_id) REFERENCES company_organization (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_230A18D132C8A3DE ON user_subscription (organization_id)');
            $this->addSql("UPDATE user_subscription SET quantity = 6 WHERE LOWER(COALESCE(plan_code, '')) = 'team' AND stripe_price_id IS NULL AND quantity < 6");
            $this->addSql("INSERT INTO company_organization (owner_id, name, status, created_at, updated_at) SELECT us.user_id, LEFT(CASE WHEN BTRIM(COALESCE(u.company, '')) <> '' THEN u.company ELSE 'Entreprise ' || SPLIT_PART(u.email, '@', 1) END, 180), 'ACTIVE', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM user_subscription us INNER JOIN \"user\" u ON u.id = us.user_id WHERE LOWER(COALESCE(us.plan_code, '')) = 'team' AND us.is_active = TRUE AND NOT EXISTS (SELECT 1 FROM company_organization existing WHERE existing.owner_id = us.user_id)");
            $this->addSql("UPDATE user_subscription SET organization_id = organization.id FROM company_organization organization WHERE organization.owner_id = user_subscription.user_id AND LOWER(COALESCE(user_subscription.plan_code, '')) = 'team'");
            $this->addSql("INSERT INTO organization_member (organization_id, user_id, invited_by_id, role, status, invited_at, invitation_expires_at, joined_at) SELECT organization.id, organization.owner_id, NULL, 'OWNER', 'ACTIVE', CURRENT_TIMESTAMP, NULL, CURRENT_TIMESTAMP FROM company_organization organization ON CONFLICT (organization_id, user_id) DO NOTHING");
            $this->addSql("INSERT INTO organization_member (organization_id, user_id, invited_by_id, role, status, invited_at, invitation_expires_at, joined_at) SELECT DISTINCT organization.id, member.user_id, organization.owner_id, CASE WHEN member_user.roles::text LIKE '%ROLE_GUEST%' THEN 'GUEST' ELSE 'MEMBER' END, CASE WHEN member_user.roles::text LIKE '%ROLE_GUEST%' THEN 'PENDING' ELSE 'ACTIVE' END, CURRENT_TIMESTAMP, CASE WHEN member_user.roles::text LIKE '%ROLE_GUEST%' THEN member_user.token_expires_at ELSE NULL END, CASE WHEN member_user.roles::text LIKE '%ROLE_GUEST%' THEN NULL ELSE CURRENT_TIMESTAMP END FROM team_member member INNER JOIN team team_record ON team_record.id = member.team_id INNER JOIN company_organization organization ON organization.owner_id = team_record.owner_id INNER JOIN \"user\" member_user ON member_user.id = member.user_id ON CONFLICT (organization_id, user_id) DO NOTHING");
            $this->addSql('UPDATE team SET organization_id = organization.id FROM company_organization organization WHERE organization.owner_id = team.owner_id AND team.organization_id IS NULL');

            return;
        }

        $this->abortIf(true, sprintf('Unsupported platform: %s', $platform));
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();
        if ($platform === 'mysql') {
            $this->addSql('ALTER TABLE team DROP FOREIGN KEY FK_C4E0A61F32C8A3DE, DROP INDEX IDX_C4E0A61F32C8A3DE, DROP organization_id');
            $this->addSql('ALTER TABLE user_subscription DROP FOREIGN KEY FK_230A18D132C8A3DE, DROP INDEX UNIQ_230A18D132C8A3DE, DROP organization_id');
        } elseif ($platform === 'postgresql') {
            $this->addSql('ALTER TABLE team DROP CONSTRAINT FK_C4E0A61F32C8A3DE');
            $this->addSql('ALTER TABLE user_subscription DROP CONSTRAINT FK_230A18D132C8A3DE');
            $this->addSql('ALTER TABLE team DROP organization_id');
            $this->addSql('ALTER TABLE user_subscription DROP organization_id');
        } else {
            $this->abortIf(true, sprintf('Unsupported platform: %s', $platform));
        }
        $this->addSql('DROP TABLE organization_member');
        $this->addSql('DROP TABLE company_organization');
    }
}
