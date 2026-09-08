<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add communication intents, transactional outbox, and webhook inbox';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE notification_intents (id UUID NOT NULL, organization_id UUID NOT NULL, type VARCHAR(64) NOT NULL, recipient_channel_id UUID DEFAULT NULL, payload JSONB NOT NULL, dedupe_key VARCHAR(160) DEFAULT NULL, status VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT chk_notification_intent_status CHECK (status IN ('PENDING', 'CANCELLED')))");
        $this->addSql('CREATE INDEX IDX_NOTIFICATION_INTENTS_ORGANIZATION ON notification_intents (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_notification_intents_tenant_id ON notification_intents (organization_id, id)');
        $this->addSql('CREATE INDEX idx_notification_intents_pending ON notification_intents (organization_id, status, created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_notification_intents_dedupe ON notification_intents (organization_id, dedupe_key) WHERE dedupe_key IS NOT NULL');
        $this->addSql('ALTER TABLE notification_intents ADD CONSTRAINT fk_notification_intents_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification_intents ADD CONSTRAINT fk_notification_intents_channel_tenant FOREIGN KEY (organization_id, recipient_channel_id) REFERENCES channel_connections (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');

        $this->addSql("CREATE TABLE communication_outbox (id UUID NOT NULL, organization_id UUID NOT NULL, notification_intent_id UUID NOT NULL, channel_connection_id UUID DEFAULT NULL, provider VARCHAR(16) NOT NULL, recipient_address VARCHAR(254) NOT NULL, body TEXT NOT NULL, buttons JSONB NOT NULL, metadata JSONB NOT NULL, status VARCHAR(16) NOT NULL, attempts INT NOT NULL, available_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, published_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, provider_message_id VARCHAR(200) DEFAULT NULL, last_error VARCHAR(500) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT chk_communication_outbox_status CHECK (status IN ('PENDING', 'PROCESSING', 'SENT', 'FAILED')), CONSTRAINT chk_communication_outbox_attempts CHECK (attempts >= 0))");
        $this->addSql('CREATE INDEX IDX_COMMUNICATION_OUTBOX_ORGANIZATION ON communication_outbox (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_communication_outbox_tenant_id ON communication_outbox (organization_id, id)');
        $this->addSql('CREATE INDEX idx_communication_outbox_pending ON communication_outbox (organization_id, status, available_at)');
        $this->addSql('CREATE INDEX idx_communication_outbox_intent ON communication_outbox (organization_id, notification_intent_id)');
        $this->addSql('ALTER TABLE communication_outbox ADD CONSTRAINT fk_communication_outbox_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE communication_outbox ADD CONSTRAINT fk_communication_outbox_intent_tenant FOREIGN KEY (organization_id, notification_intent_id) REFERENCES notification_intents (organization_id, id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE communication_outbox ADD CONSTRAINT fk_communication_outbox_channel_tenant FOREIGN KEY (organization_id, channel_connection_id) REFERENCES channel_connections (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');

        $this->addSql("CREATE TABLE communication_webhook_inbox (id UUID NOT NULL, organization_id UUID NOT NULL, provider VARCHAR(16) NOT NULL, external_event_id VARCHAR(200) NOT NULL, payload JSONB NOT NULL, status VARCHAR(16) NOT NULL, received_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, processed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, processing_error VARCHAR(500) DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT chk_communication_webhook_status CHECK (status IN ('RECEIVED', 'PROCESSED', 'FAILED')))");
        $this->addSql('CREATE INDEX IDX_COMMUNICATION_WEBHOOK_ORGANIZATION ON communication_webhook_inbox (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_communication_webhook_tenant_id ON communication_webhook_inbox (organization_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_communication_webhook_event ON communication_webhook_inbox (organization_id, provider, external_event_id)');
        $this->addSql('CREATE INDEX idx_communication_webhook_status ON communication_webhook_inbox (organization_id, status, received_at)');
        $this->addSql('ALTER TABLE communication_webhook_inbox ADD CONSTRAINT fk_communication_webhook_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE communication_webhook_inbox');
        $this->addSql('DROP TABLE communication_outbox');
        $this->addSql('DROP TABLE notification_intents');
    }
}
