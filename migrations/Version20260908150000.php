<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bind communication webhook routing to channel connections';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE channel_connections ADD webhook_routing_key VARCHAR(64) DEFAULT NULL");
        $this->addSql("UPDATE channel_connections SET webhook_routing_key = md5(id::text || clock_timestamp()::text) WHERE webhook_routing_key IS NULL");
        $this->addSql("ALTER TABLE channel_connections ALTER COLUMN webhook_routing_key SET NOT NULL");
        $this->addSql("ALTER TABLE channel_connections ADD webhook_secret_hash VARCHAR(128) DEFAULT NULL");
        $this->addSql("ALTER TABLE channel_connections ADD active BOOLEAN NOT NULL DEFAULT TRUE");
        $this->addSql("CREATE UNIQUE INDEX uniq_channel_connections_webhook_route ON channel_connections (provider, webhook_routing_key)");
        $this->addSql("ALTER TABLE communication_webhook_inbox ADD channel_connection_id UUID DEFAULT NULL");
        $this->addSql("CREATE INDEX idx_communication_webhook_connection ON communication_webhook_inbox (organization_id, channel_connection_id)");
        $this->addSql("ALTER TABLE communication_webhook_inbox ADD CONSTRAINT fk_communication_webhook_channel_tenant FOREIGN KEY (organization_id, channel_connection_id) REFERENCES channel_connections (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communication_webhook_inbox DROP CONSTRAINT fk_communication_webhook_channel_tenant');
        $this->addSql('DROP INDEX idx_communication_webhook_connection');
        $this->addSql('ALTER TABLE communication_webhook_inbox DROP channel_connection_id');
        $this->addSql('DROP INDEX uniq_channel_connections_webhook_route');
        $this->addSql('ALTER TABLE channel_connections DROP active');
        $this->addSql('ALTER TABLE channel_connections DROP webhook_secret_hash');
        $this->addSql('ALTER TABLE channel_connections DROP webhook_routing_key');
    }
}
