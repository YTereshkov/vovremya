<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add appointment results, history actors, scheduling settings, and free windows';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE appointments ADD result_status VARCHAR(32) DEFAULT NULL, ADD result_recorded_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, ADD result_is_late BOOLEAN DEFAULT NULL, ADD result_respectful_reason BOOLEAN NOT NULL DEFAULT FALSE, ADD result_comment VARCHAR(1000) DEFAULT NULL");
        $this->addSql('ALTER TABLE appointments ALTER result_respectful_reason DROP DEFAULT');
        $this->addSql("ALTER TABLE appointments ADD CONSTRAINT chk_appointment_result_status CHECK (result_status IS NULL OR result_status IN ('CONDUCTED', 'CANCELLED_BY_CLIENT', 'CANCELLED_BY_SPECIALIST', 'NO_SHOW', 'RESCHEDULED'))");
        $this->addSql("ALTER TABLE appointments ADD CONSTRAINT chk_appointment_result_lifecycle CHECK ((result_status IS NULL AND result_recorded_at IS NULL AND result_is_late IS NULL AND result_respectful_reason = FALSE AND result_comment IS NULL) OR (result_status IS NOT NULL AND result_recorded_at IS NOT NULL AND ((result_status = 'CANCELLED_BY_CLIENT' AND result_is_late IS NOT NULL AND (result_respectful_reason = FALSE OR result_is_late = TRUE)) OR (result_status <> 'CANCELLED_BY_CLIENT' AND result_is_late IS NULL AND result_respectful_reason = FALSE))))");
        $this->addSql('ALTER TABLE appointment_events ALTER actor_administrator_id DROP NOT NULL');

        $this->addSql('CREATE TABLE scheduling_settings (id UUID NOT NULL, organization_id UUID NOT NULL, late_cancellation_hours INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_scheduling_settings_tenant_id ON scheduling_settings (organization_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_scheduling_settings_organization ON scheduling_settings (organization_id)');
        $this->addSql('ALTER TABLE scheduling_settings ADD CONSTRAINT chk_scheduling_settings_late_hours CHECK (late_cancellation_hours BETWEEN 1 AND 168)');
        $this->addSql('ALTER TABLE scheduling_settings ADD CONSTRAINT fk_scheduling_settings_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');

        $this->addSql('CREATE TABLE free_windows (id UUID NOT NULL, organization_id UUID NOT NULL, source_appointment_id UUID NOT NULL, specialist_id UUID NOT NULL, service_id UUID NOT NULL, service_name_snapshot VARCHAR(160) NOT NULL, duration_minutes SMALLINT NOT NULL, starts_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, status VARCHAR(16) NOT NULL, closed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, closed_reason VARCHAR(48) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_free_windows_tenant_id ON free_windows (organization_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_free_windows_source ON free_windows (organization_id, source_appointment_id)');
        $this->addSql('CREATE INDEX idx_free_windows_open ON free_windows (organization_id, status, starts_at)');
        $this->addSql("ALTER TABLE free_windows ADD CONSTRAINT chk_free_windows_status CHECK (status IN ('OPEN', 'CLOSED'))");
        $this->addSql('ALTER TABLE free_windows ADD CONSTRAINT chk_free_windows_interval CHECK (ends_at > starts_at AND duration_minutes BETWEEN 1 AND 1440)');
        $this->addSql("ALTER TABLE free_windows ADD CONSTRAINT chk_free_windows_lifecycle CHECK ((status = 'OPEN' AND closed_at IS NULL AND closed_reason IS NULL) OR (status = 'CLOSED' AND closed_at IS NOT NULL AND closed_reason IS NOT NULL))");
        $this->addSql('ALTER TABLE free_windows ADD CONSTRAINT fk_free_windows_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE free_windows ADD CONSTRAINT fk_free_windows_appointment_tenant FOREIGN KEY (organization_id, source_appointment_id) REFERENCES appointments (organization_id, id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE free_windows ADD CONSTRAINT fk_free_windows_specialist_tenant FOREIGN KEY (organization_id, specialist_id) REFERENCES specialists (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE free_windows ADD CONSTRAINT fk_free_windows_service_tenant FOREIGN KEY (organization_id, service_id) REFERENCES services (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE free_windows');
        $this->addSql('DROP TABLE scheduling_settings');
        $this->addSql('DELETE FROM appointment_events WHERE actor_administrator_id IS NULL');
        $this->addSql('ALTER TABLE appointment_events ALTER actor_administrator_id SET NOT NULL');
        $this->addSql('ALTER TABLE appointments DROP CONSTRAINT chk_appointment_result_lifecycle, DROP CONSTRAINT chk_appointment_result_status');
        $this->addSql('ALTER TABLE appointments DROP result_status, DROP result_recorded_at, DROP result_is_late, DROP result_respectful_reason, DROP result_comment');
    }
}
