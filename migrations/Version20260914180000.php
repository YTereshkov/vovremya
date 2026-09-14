<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add delivery tracking and per-administrator notification read state';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communication_outbox ADD delivered_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE communication_outbox ADD read_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE communication_outbox DROP CONSTRAINT chk_communication_outbox_status');
        $this->addSql("ALTER TABLE communication_outbox ADD CONSTRAINT chk_communication_outbox_status CHECK (status IN ('PENDING', 'PROCESSING', 'SENT', 'DELIVERED', 'READ', 'FAILED'))");
        $this->addSql('CREATE UNIQUE INDEX uniq_communication_outbox_provider_message ON communication_outbox (organization_id, provider, provider_message_id) WHERE provider_message_id IS NOT NULL');
        $this->addSql('CREATE INDEX idx_communication_outbox_attention ON communication_outbox (organization_id, status, created_at DESC)');
        $this->addSql('CREATE TABLE administrator_notification_states (id UUID NOT NULL, organization_id UUID NOT NULL, administrator_id UUID NOT NULL, read_through TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_admin_notification_states_tenant_id ON administrator_notification_states (organization_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_admin_notification_states_admin ON administrator_notification_states (organization_id, administrator_id)');
        $this->addSql('ALTER TABLE administrator_notification_states ADD CONSTRAINT fk_admin_notification_states_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE administrator_notification_states ADD CONSTRAINT fk_admin_notification_states_admin_tenant FOREIGN KEY (organization_id, administrator_id) REFERENCES administrator_accounts (organization_id, id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE administrator_notification_states');
        $this->addSql('DROP INDEX idx_communication_outbox_attention');
        $this->addSql('DROP INDEX uniq_communication_outbox_provider_message');
        $this->addSql('ALTER TABLE communication_outbox DROP CONSTRAINT chk_communication_outbox_status');
        $this->addSql("ALTER TABLE communication_outbox ADD CONSTRAINT chk_communication_outbox_status CHECK (status IN ('PENDING', 'PROCESSING', 'SENT', 'FAILED'))");
        $this->addSql('ALTER TABLE communication_outbox DROP delivered_at');
        $this->addSql('ALTER TABLE communication_outbox DROP read_at');
    }
}
