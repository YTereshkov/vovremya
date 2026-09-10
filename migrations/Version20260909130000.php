<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Complete channel activation lifecycle and organization default channel';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE organizations ADD default_channel VARCHAR(16) NOT NULL DEFAULT 'MAX'");
        $this->addSql("ALTER TABLE organizations ADD CONSTRAINT chk_organizations_default_channel CHECK (default_channel IN ('MAX', 'TELEGRAM', 'WHATSAPP'))");
        $this->addSql('ALTER TABLE channel_connections ADD activation_token_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE channel_connections ADD activation_expires_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_channel_connections_activation ON channel_connections (organization_id, activation_token_hash) WHERE activation_token_hash IS NOT NULL');
        $this->addSql('ALTER TABLE channel_connections ADD CONSTRAINT chk_channel_activation_pair CHECK ((activation_token_hash IS NULL) = (activation_expires_at IS NULL))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE channel_connections DROP CONSTRAINT chk_channel_activation_pair');
        $this->addSql('DROP INDEX idx_channel_connections_activation');
        $this->addSql('ALTER TABLE channel_connections DROP activation_expires_at');
        $this->addSql('ALTER TABLE channel_connections DROP activation_token_hash');
        $this->addSql('ALTER TABLE organizations DROP CONSTRAINT chk_organizations_default_channel');
        $this->addSql('ALTER TABLE organizations DROP default_channel');
    }
}
