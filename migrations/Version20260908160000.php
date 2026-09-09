<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist normalized communication webhook events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE communication_normalized_events (id UUID NOT NULL, organization_id UUID NOT NULL, channel_connection_id UUID DEFAULT NULL, provider VARCHAR(16) NOT NULL, external_event_id VARCHAR(200) NOT NULL, payload JSONB NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_normalized_events_tenant_id ON communication_normalized_events (organization_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_normalized_events_external ON communication_normalized_events (organization_id, provider, external_event_id)');
        $this->addSql('CREATE INDEX idx_normalized_events_tenant_created ON communication_normalized_events (organization_id, created_at)');
        $this->addSql('ALTER TABLE communication_normalized_events ADD CONSTRAINT fk_normalized_events_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE communication_normalized_events ADD CONSTRAINT fk_normalized_events_channel_tenant FOREIGN KEY (organization_id, channel_connection_id) REFERENCES channel_connections (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE communication_normalized_events');
    }
}
