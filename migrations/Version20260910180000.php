<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add specialist and client absences';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE specialist_absences (id UUID NOT NULL, organization_id UUID NOT NULL, specialist_id UUID NOT NULL, absence_type VARCHAR(24) NOT NULL, starts_on DATE NOT NULL, ends_on DATE NOT NULL, comment VARCHAR(1000) DEFAULT NULL, notify_clients BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_specialist_absences_tenant_id ON specialist_absences (organization_id, id)');
        $this->addSql('CREATE INDEX idx_specialist_absences_period ON specialist_absences (organization_id, specialist_id, ends_on, starts_on)');
        $this->addSql("ALTER TABLE specialist_absences ADD CONSTRAINT chk_specialist_absences_type CHECK (absence_type IN ('VACATION', 'SICK_LEAVE', 'OTHER'))");
        $this->addSql('ALTER TABLE specialist_absences ADD CONSTRAINT chk_specialist_absences_period CHECK (ends_on >= starts_on)');
        $this->addSql('ALTER TABLE specialist_absences ADD CONSTRAINT fk_specialist_absences_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE specialist_absences ADD CONSTRAINT fk_specialist_absences_specialist_tenant FOREIGN KEY (organization_id, specialist_id) REFERENCES specialists (organization_id, id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE client_absences (id UUID NOT NULL, organization_id UUID NOT NULL, client_id UUID NOT NULL, starts_on DATE NOT NULL, ends_on DATE NOT NULL, reason VARCHAR(160) DEFAULT NULL, mode VARCHAR(32) NOT NULL, create_free_windows BOOLEAN NOT NULL, notify_client BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_client_absences_tenant_id ON client_absences (organization_id, id)');
        $this->addSql('CREATE INDEX idx_client_absences_period ON client_absences (organization_id, client_id, ends_on, starts_on)');
        $this->addSql("ALTER TABLE client_absences ADD CONSTRAINT chk_client_absences_mode CHECK (mode IN ('KEEP_PERMANENT_PLACE', 'RELEASE_PERMANENT_PLACE'))");
        $this->addSql('ALTER TABLE client_absences ADD CONSTRAINT chk_client_absences_period CHECK (ends_on >= starts_on)');
        $this->addSql("ALTER TABLE client_absences ADD CONSTRAINT chk_client_absences_windows CHECK (mode = 'KEEP_PERMANENT_PLACE' OR create_free_windows = FALSE)");
        $this->addSql('ALTER TABLE client_absences ADD CONSTRAINT fk_client_absences_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE client_absences ADD CONSTRAINT fk_client_absences_client_tenant FOREIGN KEY (organization_id, client_id) REFERENCES clients (organization_id, id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE INDEX idx_appointments_client_start ON appointments (organization_id, client_id, starts_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_appointments_client_start');
        $this->addSql('DROP TABLE client_absences');
        $this->addSql('DROP TABLE specialist_absences');
    }
}
