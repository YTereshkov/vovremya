<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add regular schedules, generated occurrence links, and generation issues';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE regular_schedules (id UUID NOT NULL, organization_id UUID NOT NULL, specialist_id UUID NOT NULL, client_id UUID NOT NULL, service_id UUID NOT NULL, service_name_snapshot VARCHAR(160) NOT NULL, service_default_duration_snapshot SMALLINT NOT NULL, service_minimum_duration_snapshot SMALLINT DEFAULT NULL, service_maximum_duration_snapshot SMALLINT DEFAULT NULL, starts_on DATE NOT NULL, inactive_from DATE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT chk_regular_schedule_dates CHECK (inactive_from IS NULL OR inactive_from >= starts_on))');
        $this->addSql('CREATE INDEX IDX_9351D55C32C8A3DE ON regular_schedules (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_regular_schedules_tenant_id ON regular_schedules (organization_id, id)');
        $this->addSql('CREATE INDEX idx_regular_schedules_active ON regular_schedules (organization_id, inactive_from, starts_on)');
        $this->addSql('CREATE INDEX idx_regular_schedules_client ON regular_schedules (organization_id, client_id)');
        $this->addSql('ALTER TABLE regular_schedules ADD CONSTRAINT FK_9066CE0532C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE regular_schedules ADD CONSTRAINT fk_regular_schedules_specialist_tenant FOREIGN KEY (organization_id, specialist_id) REFERENCES specialists (organization_id, id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE regular_schedules ADD CONSTRAINT fk_regular_schedules_client_tenant FOREIGN KEY (organization_id, client_id) REFERENCES clients (organization_id, id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE regular_schedules ADD CONSTRAINT fk_regular_schedules_service_tenant FOREIGN KEY (organization_id, service_id) REFERENCES services (organization_id, id) ON DELETE RESTRICT');

        $this->addSql("CREATE TABLE regular_schedule_days (id UUID NOT NULL, organization_id UUID NOT NULL, regular_schedule_id UUID NOT NULL, weekday SMALLINT NOT NULL, start_time VARCHAR(5) NOT NULL, duration_minutes SMALLINT NOT NULL, active_from DATE NOT NULL, inactive_from DATE DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT chk_regular_schedule_day_weekday CHECK (weekday BETWEEN 1 AND 7), CONSTRAINT chk_regular_schedule_day_time CHECK (start_time ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$'), CONSTRAINT chk_regular_schedule_day_duration CHECK (duration_minutes BETWEEN 1 AND 1440), CONSTRAINT chk_regular_schedule_day_dates CHECK (inactive_from IS NULL OR inactive_from >= active_from))");
        $this->addSql('CREATE INDEX IDX_2FEAD74932C8A3DE ON regular_schedule_days (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_regular_schedule_days_tenant_id ON regular_schedule_days (organization_id, id)');
        $this->addSql('CREATE INDEX idx_regular_schedule_days_active ON regular_schedule_days (organization_id, regular_schedule_id, inactive_from)');
        $this->addSql('ALTER TABLE regular_schedule_days ADD CONSTRAINT FK_5D64C44D32C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE regular_schedule_days ADD CONSTRAINT fk_regular_schedule_days_schedule_tenant FOREIGN KEY (organization_id, regular_schedule_id) REFERENCES regular_schedules (organization_id, id) ON DELETE CASCADE');

        $this->addSql("CREATE TABLE schedule_generation_issues (id UUID NOT NULL, organization_id UUID NOT NULL, regular_schedule_id UUID NOT NULL, regular_schedule_day_id UUID NOT NULL, occurrence_date DATE NOT NULL, conflict_code VARCHAR(48) NOT NULL, conflict_message VARCHAR(300) NOT NULL, status VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, resolved_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT chk_schedule_generation_issue_status CHECK (status IN ('OPEN', 'RESOLVED')))");
        $this->addSql('CREATE INDEX IDX_2167CCAB32C8A3DE ON schedule_generation_issues (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_schedule_generation_issues_tenant_id ON schedule_generation_issues (organization_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_schedule_generation_issue_occurrence ON schedule_generation_issues (organization_id, regular_schedule_id, occurrence_date)');
        $this->addSql('CREATE INDEX idx_schedule_generation_issues_open ON schedule_generation_issues (organization_id, status, occurrence_date)');
        $this->addSql('ALTER TABLE schedule_generation_issues ADD CONSTRAINT FK_9CDE13D432C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE schedule_generation_issues ADD CONSTRAINT fk_schedule_generation_issues_schedule_tenant FOREIGN KEY (organization_id, regular_schedule_id) REFERENCES regular_schedules (organization_id, id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE schedule_generation_issues ADD CONSTRAINT fk_schedule_generation_issues_day_tenant FOREIGN KEY (organization_id, regular_schedule_day_id) REFERENCES regular_schedule_days (organization_id, id) ON DELETE CASCADE');

        $this->addSql("ALTER TABLE appointments ADD regular_schedule_id UUID DEFAULT NULL, ADD regular_schedule_day_id UUID DEFAULT NULL, ADD occurrence_date DATE DEFAULT NULL, ADD planning_status VARCHAR(32) NOT NULL DEFAULT 'PLANNED'");
        $this->addSql('ALTER TABLE appointments ALTER planning_status DROP DEFAULT');
        $this->addSql("ALTER TABLE appointments ADD CONSTRAINT chk_appointment_planning_status CHECK (planning_status IN ('PLANNED', 'REMOVED_FROM_SCHEDULE'))");
        $this->addSql('ALTER TABLE appointments ADD CONSTRAINT chk_appointment_recurrence_links CHECK ((regular_schedule_id IS NULL AND regular_schedule_day_id IS NULL AND occurrence_date IS NULL) OR (regular_schedule_id IS NOT NULL AND regular_schedule_day_id IS NOT NULL AND occurrence_date IS NOT NULL))');
        $this->addSql('CREATE INDEX idx_appointments_regular_schedule ON appointments (organization_id, regular_schedule_id, starts_at)');
        $this->addSql("CREATE UNIQUE INDEX uniq_appointment_regular_occurrence ON appointments (organization_id, regular_schedule_id, occurrence_date) WHERE regular_schedule_id IS NOT NULL AND planning_status = 'PLANNED'");
        $this->addSql('ALTER TABLE appointments ADD CONSTRAINT fk_appointments_regular_schedule_tenant FOREIGN KEY (organization_id, regular_schedule_id) REFERENCES regular_schedules (organization_id, id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE appointments ADD CONSTRAINT fk_appointments_regular_day_tenant FOREIGN KEY (organization_id, regular_schedule_day_id) REFERENCES regular_schedule_days (organization_id, id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE appointments DROP CONSTRAINT fk_appointments_regular_schedule_tenant');
        $this->addSql('ALTER TABLE appointments DROP CONSTRAINT fk_appointments_regular_day_tenant');
        $this->addSql('DROP INDEX uniq_appointment_regular_occurrence');
        $this->addSql('DROP INDEX idx_appointments_regular_schedule');
        $this->addSql('ALTER TABLE appointments DROP CONSTRAINT chk_appointment_recurrence_links');
        $this->addSql('ALTER TABLE appointments DROP CONSTRAINT chk_appointment_planning_status');
        $this->addSql('ALTER TABLE appointments DROP regular_schedule_id, DROP regular_schedule_day_id, DROP occurrence_date, DROP planning_status');
        $this->addSql('DROP TABLE schedule_generation_issues');
        $this->addSql('DROP TABLE regular_schedule_days');
        $this->addSql('DROP TABLE regular_schedules');
    }
}
