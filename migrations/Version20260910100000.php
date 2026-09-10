<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add confirmation settings, requests, and single-use actions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE confirmation_settings (id UUID NOT NULL, organization_id UUID NOT NULL, request_time VARCHAR(5) NOT NULL, no_response_time VARCHAR(5) NOT NULL, reminder_enabled BOOLEAN NOT NULL, reminder_lead_minutes INT NOT NULL, reminder_not_before VARCHAR(5) NOT NULL, quiet_hours_start VARCHAR(5) NOT NULL, quiet_hours_end VARCHAR(5) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_confirmation_settings_tenant_id ON confirmation_settings (organization_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_confirmation_settings_organization ON confirmation_settings (organization_id)');
        $this->addSql("ALTER TABLE confirmation_settings ADD CONSTRAINT chk_confirmation_settings_times CHECK (request_time ~ '^(?:[01][0-9]|2[0-3]):[0-5][0-9]$' AND no_response_time ~ '^(?:[01][0-9]|2[0-3]):[0-5][0-9]$' AND reminder_not_before ~ '^(?:[01][0-9]|2[0-3]):[0-5][0-9]$' AND quiet_hours_start ~ '^(?:[01][0-9]|2[0-3]):[0-5][0-9]$' AND quiet_hours_end ~ '^(?:[01][0-9]|2[0-3]):[0-5][0-9]$')");
        $this->addSql('ALTER TABLE confirmation_settings ADD CONSTRAINT chk_confirmation_settings_order CHECK (no_response_time > request_time)');
        $this->addSql('ALTER TABLE confirmation_settings ADD CONSTRAINT chk_confirmation_settings_reminder CHECK (reminder_lead_minutes BETWEEN 15 AND 1440)');
        $this->addSql('ALTER TABLE confirmation_settings ADD CONSTRAINT chk_confirmation_settings_quiet CHECK (quiet_hours_start <> quiet_hours_end)');
        $this->addSql('ALTER TABLE confirmation_settings ADD CONSTRAINT fk_confirmation_settings_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');

        $this->addSql('CREATE TABLE appointment_confirmation_requests (id UUID NOT NULL, organization_id UUID NOT NULL, appointment_id UUID NOT NULL, channel_connection_id UUID NOT NULL, status VARCHAR(24) NOT NULL, requested_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, responded_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, no_response_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, reminder_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_confirmation_requests_tenant_id ON appointment_confirmation_requests (organization_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_confirmation_requests_appointment ON appointment_confirmation_requests (organization_id, appointment_id)');
        $this->addSql('CREATE INDEX idx_confirmation_requests_status ON appointment_confirmation_requests (organization_id, status, requested_at)');
        $this->addSql("ALTER TABLE appointment_confirmation_requests ADD CONSTRAINT chk_confirmation_requests_status CHECK (status IN ('PENDING', 'CONFIRMED', 'CANNOT_ATTEND', 'NO_RESPONSE'))");
        $this->addSql("ALTER TABLE appointment_confirmation_requests ADD CONSTRAINT chk_confirmation_requests_lifecycle CHECK ((status = 'PENDING' AND responded_at IS NULL AND no_response_at IS NULL) OR (status IN ('CONFIRMED', 'CANNOT_ATTEND') AND responded_at IS NOT NULL) OR (status = 'NO_RESPONSE' AND responded_at IS NULL AND no_response_at IS NOT NULL))");
        $this->addSql('ALTER TABLE appointment_confirmation_requests ADD CONSTRAINT fk_confirmation_requests_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE appointment_confirmation_requests ADD CONSTRAINT fk_confirmation_requests_appointment_tenant FOREIGN KEY (organization_id, appointment_id) REFERENCES appointments (organization_id, id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE appointment_confirmation_requests ADD CONSTRAINT fk_confirmation_requests_channel_tenant FOREIGN KEY (organization_id, channel_connection_id) REFERENCES channel_connections (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');

        $this->addSql('CREATE TABLE appointment_confirmation_actions (id UUID NOT NULL, organization_id UUID NOT NULL, confirmation_request_id UUID NOT NULL, action_type VARCHAR(24) NOT NULL, token_hash VARCHAR(64) NOT NULL, consumed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_confirmation_actions_tenant_id ON appointment_confirmation_actions (organization_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_confirmation_actions_token ON appointment_confirmation_actions (organization_id, token_hash)');
        $this->addSql("ALTER TABLE appointment_confirmation_actions ADD CONSTRAINT chk_confirmation_actions_type CHECK (action_type IN ('CONFIRM', 'CANNOT_ATTEND'))");
        $this->addSql("ALTER TABLE appointment_confirmation_actions ADD CONSTRAINT chk_confirmation_actions_token CHECK (token_hash ~ '^[a-f0-9]{64}$')");
        $this->addSql('ALTER TABLE appointment_confirmation_actions ADD CONSTRAINT fk_confirmation_actions_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE appointment_confirmation_actions ADD CONSTRAINT fk_confirmation_actions_request_tenant FOREIGN KEY (organization_id, confirmation_request_id) REFERENCES appointment_confirmation_requests (organization_id, id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE appointment_confirmation_actions');
        $this->addSql('DROP TABLE appointment_confirmation_requests');
        $this->addSql('DROP TABLE confirmation_settings');
    }
}
