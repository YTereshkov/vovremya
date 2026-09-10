<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add non-reserving appointment transfer requests and options';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE transfer_requests (id UUID NOT NULL, organization_id UUID NOT NULL, appointment_id UUID NOT NULL, channel_connection_id UUID NOT NULL, status VARCHAR(24) NOT NULL, decline_token_hash VARCHAR(64) DEFAULT NULL, new_appointment_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, options_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, closed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_transfer_requests_tenant_id ON transfer_requests (organization_id, id)');
        $this->addSql('CREATE INDEX idx_transfer_requests_appointment ON transfer_requests (organization_id, appointment_id, created_at)');
        $this->addSql('CREATE INDEX idx_transfer_requests_status ON transfer_requests (organization_id, status, created_at)');
        $this->addSql("CREATE UNIQUE INDEX uniq_transfer_requests_active_appointment ON transfer_requests (organization_id, appointment_id) WHERE status IN ('AWAITING_OPTIONS', 'OPTIONS_SENT')");
        $this->addSql("ALTER TABLE transfer_requests ADD CONSTRAINT chk_transfer_requests_status CHECK (status IN ('AWAITING_OPTIONS', 'OPTIONS_SENT', 'COMPLETED', 'DECLINED', 'CANCELLED'))");
        $this->addSql("ALTER TABLE transfer_requests ADD CONSTRAINT chk_transfer_requests_lifecycle CHECK ((status = 'AWAITING_OPTIONS' AND decline_token_hash IS NULL AND options_sent_at IS NULL AND closed_at IS NULL AND new_appointment_id IS NULL) OR (status = 'OPTIONS_SENT' AND decline_token_hash IS NOT NULL AND options_sent_at IS NOT NULL AND closed_at IS NULL AND new_appointment_id IS NULL) OR (status = 'COMPLETED' AND decline_token_hash IS NOT NULL AND options_sent_at IS NOT NULL AND closed_at IS NOT NULL AND new_appointment_id IS NOT NULL) OR (status = 'DECLINED' AND decline_token_hash IS NOT NULL AND options_sent_at IS NOT NULL AND closed_at IS NOT NULL AND new_appointment_id IS NULL) OR (status = 'CANCELLED' AND closed_at IS NOT NULL AND new_appointment_id IS NULL))");
        $this->addSql('ALTER TABLE transfer_requests ADD CONSTRAINT fk_transfer_requests_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE transfer_requests ADD CONSTRAINT fk_transfer_requests_appointment_tenant FOREIGN KEY (organization_id, appointment_id) REFERENCES appointments (organization_id, id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE transfer_requests ADD CONSTRAINT fk_transfer_requests_channel_tenant FOREIGN KEY (organization_id, channel_connection_id) REFERENCES channel_connections (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE transfer_requests ADD CONSTRAINT fk_transfer_requests_new_appointment_tenant FOREIGN KEY (organization_id, new_appointment_id) REFERENCES appointments (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');

        $this->addSql('CREATE TABLE transfer_options (id UUID NOT NULL, organization_id UUID NOT NULL, transfer_request_id UUID NOT NULL, starts_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, token_hash VARCHAR(64) NOT NULL, selected_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_transfer_options_tenant_id ON transfer_options (organization_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_transfer_options_interval ON transfer_options (organization_id, transfer_request_id, starts_at)');
        $this->addSql('ALTER TABLE transfer_options ADD CONSTRAINT chk_transfer_options_period CHECK (ends_at > starts_at)');
        $this->addSql('ALTER TABLE transfer_options ADD CONSTRAINT fk_transfer_options_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE transfer_options ADD CONSTRAINT fk_transfer_options_request_tenant FOREIGN KEY (organization_id, transfer_request_id) REFERENCES transfer_requests (organization_id, id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('ALTER TABLE appointment_confirmation_actions DROP CONSTRAINT chk_confirmation_actions_type');
        $this->addSql("ALTER TABLE appointment_confirmation_actions ADD CONSTRAINT chk_confirmation_actions_type CHECK (action_type IN ('CONFIRM', 'CANNOT_ATTEND', 'REQUEST_TRANSFER'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM appointment_confirmation_actions WHERE action_type = 'REQUEST_TRANSFER'");
        $this->addSql('ALTER TABLE appointment_confirmation_actions DROP CONSTRAINT chk_confirmation_actions_type');
        $this->addSql("ALTER TABLE appointment_confirmation_actions ADD CONSTRAINT chk_confirmation_actions_type CHECK (action_type IN ('CONFIRM', 'CANNOT_ATTEND'))");
        $this->addSql('DROP TABLE transfer_options');
        $this->addSql('DROP TABLE transfer_requests');
    }
}
