<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260907230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add one-off appointments and warning acceptance audit events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE appointments (id UUID NOT NULL, organization_id UUID NOT NULL, specialist_id UUID NOT NULL, client_id UUID NOT NULL, service_id UUID NOT NULL, service_name_snapshot VARCHAR(160) NOT NULL, service_default_duration_snapshot SMALLINT NOT NULL, service_minimum_duration_snapshot SMALLINT DEFAULT NULL, service_maximum_duration_snapshot SMALLINT DEFAULT NULL, duration_minutes SMALLINT NOT NULL, starts_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT chk_appointment_interval CHECK (ends_at > starts_at), CONSTRAINT chk_appointment_duration CHECK (duration_minutes BETWEEN 1 AND 1440))');
        $this->addSql('CREATE INDEX IDX_6A41727A32C8A3DE ON appointments (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_appointments_tenant_id ON appointments (organization_id, id)');
        $this->addSql('CREATE INDEX idx_appointments_tenant_start ON appointments (organization_id, starts_at, id)');
        $this->addSql('CREATE INDEX idx_appointments_tenant_specialist ON appointments (organization_id, specialist_id, starts_at)');
        $this->addSql('CREATE INDEX idx_appointments_tenant_client ON appointments (organization_id, client_id)');
        $this->addSql('CREATE INDEX idx_appointments_tenant_service ON appointments (organization_id, service_id)');
        $this->addSql('ALTER TABLE appointments ADD CONSTRAINT FK_6A41727C32C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE appointments ADD CONSTRAINT fk_appointments_specialist_tenant FOREIGN KEY (organization_id, specialist_id) REFERENCES specialists (organization_id, id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE appointments ADD CONSTRAINT fk_appointments_client_tenant FOREIGN KEY (organization_id, client_id) REFERENCES clients (organization_id, id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE appointments ADD CONSTRAINT fk_appointments_service_tenant FOREIGN KEY (organization_id, service_id) REFERENCES services (organization_id, id) ON DELETE RESTRICT');

        $this->addSql('CREATE TABLE appointment_events (id UUID NOT NULL, organization_id UUID NOT NULL, appointment_id UUID NOT NULL, actor_administrator_id UUID NOT NULL, event_type VARCHAR(48) NOT NULL, payload JSONB NOT NULL, occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_14D9E80432C8A3DE ON appointment_events (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_appointment_events_tenant_id ON appointment_events (organization_id, id)');
        $this->addSql('CREATE INDEX idx_appointment_events_appointment ON appointment_events (organization_id, appointment_id, occurred_at)');
        $this->addSql('CREATE INDEX idx_appointment_events_actor ON appointment_events (organization_id, actor_administrator_id)');
        $this->addSql('ALTER TABLE appointment_events ADD CONSTRAINT FK_3BE2775A32C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE appointment_events ADD CONSTRAINT fk_appointment_events_appointment_tenant FOREIGN KEY (organization_id, appointment_id) REFERENCES appointments (organization_id, id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE appointment_events ADD CONSTRAINT fk_appointment_events_actor_tenant FOREIGN KEY (organization_id, actor_administrator_id) REFERENCES administrator_accounts (organization_id, id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE appointment_events');
        $this->addSql('DROP TABLE appointments');
    }
}
