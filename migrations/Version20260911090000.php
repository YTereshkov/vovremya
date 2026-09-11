<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tenant-scoped waiting list conditions and availability';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE waiting_list_entries (id UUID NOT NULL, organization_id UUID NOT NULL, client_id UUID NOT NULL, service_id UUID NOT NULL, specialist_id UUID DEFAULT NULL, required_frequency SMALLINT NOT NULL, ready_for_one_off BOOLEAN NOT NULL, effective_from DATE NOT NULL, comment VARCHAR(300) DEFAULT NULL, active BOOLEAN NOT NULL, ended_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_waiting_entries_tenant_id ON waiting_list_entries (organization_id, id)');
        $this->addSql('CREATE INDEX idx_waiting_entries_matching ON waiting_list_entries (organization_id, service_id, active, ready_for_one_off, effective_from)');
        $this->addSql('CREATE UNIQUE INDEX uniq_waiting_entries_active_client ON waiting_list_entries (organization_id, client_id) WHERE active = TRUE');
        $this->addSql('ALTER TABLE waiting_list_entries ADD CONSTRAINT chk_waiting_entries_frequency CHECK (required_frequency BETWEEN 1 AND 7)');
        $this->addSql('ALTER TABLE waiting_list_entries ADD CONSTRAINT chk_waiting_entries_lifecycle CHECK ((active = TRUE AND ended_at IS NULL) OR (active = FALSE AND ended_at IS NOT NULL))');
        $this->addSql('ALTER TABLE waiting_list_entries ADD CONSTRAINT fk_waiting_entries_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE waiting_list_entries ADD CONSTRAINT fk_waiting_entries_client_tenant FOREIGN KEY (organization_id, client_id) REFERENCES clients (organization_id, id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE waiting_list_entries ADD CONSTRAINT fk_waiting_entries_service_tenant FOREIGN KEY (organization_id, service_id) REFERENCES services (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE waiting_list_entries ADD CONSTRAINT fk_waiting_entries_specialist_tenant FOREIGN KEY (organization_id, specialist_id) REFERENCES specialists (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');

        $this->addSql('CREATE TABLE waiting_list_availability (id UUID NOT NULL, organization_id UUID NOT NULL, waiting_list_entry_id UUID NOT NULL, weekday SMALLINT NOT NULL, start_time VARCHAR(5) NOT NULL, end_time VARCHAR(5) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_waiting_availability_tenant_id ON waiting_list_availability (organization_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_waiting_availability_day ON waiting_list_availability (organization_id, waiting_list_entry_id, weekday)');
        $this->addSql('CREATE INDEX idx_waiting_availability_match ON waiting_list_availability (organization_id, weekday, start_time, end_time)');
        $this->addSql('ALTER TABLE waiting_list_availability ADD CONSTRAINT chk_waiting_availability_weekday CHECK (weekday BETWEEN 1 AND 7)');
        $this->addSql("ALTER TABLE waiting_list_availability ADD CONSTRAINT chk_waiting_availability_start CHECK (start_time ~ '^(?:[01][0-9]|2[0-3]):[0-5][0-9]$')");
        $this->addSql("ALTER TABLE waiting_list_availability ADD CONSTRAINT chk_waiting_availability_end CHECK (end_time IS NULL OR (end_time ~ '^(?:[01][0-9]|2[0-3]):[0-5][0-9]$' AND end_time > start_time))");
        $this->addSql('ALTER TABLE waiting_list_availability ADD CONSTRAINT fk_waiting_availability_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE waiting_list_availability ADD CONSTRAINT fk_waiting_availability_entry_tenant FOREIGN KEY (organization_id, waiting_list_entry_id) REFERENCES waiting_list_entries (organization_id, id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE waiting_list_availability');
        $this->addSql('DROP TABLE waiting_list_entries');
    }
}
