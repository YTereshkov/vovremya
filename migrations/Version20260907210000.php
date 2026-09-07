<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260907210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add schedule allocations with PostgreSQL overlap protection';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS btree_gist');
        $this->addSql("CREATE TABLE schedule_allocations (id UUID NOT NULL, organization_id UUID NOT NULL, specialist_id UUID NOT NULL, source_id UUID NOT NULL, allocation_type VARCHAR(24) NOT NULL, starts_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, released_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT chk_schedule_allocation_interval CHECK (ends_at > starts_at), CONSTRAINT chk_schedule_allocation_type CHECK (allocation_type IN ('APPOINTMENT', 'OFFER_RESERVATION')))");
        $this->addSql('CREATE INDEX IDX_586BA83532C8A3DE ON schedule_allocations (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_schedule_allocations_tenant_id ON schedule_allocations (organization_id, id)');
        $this->addSql('CREATE INDEX idx_schedule_allocations_source ON schedule_allocations (organization_id, allocation_type, source_id)');
        $this->addSql('ALTER TABLE schedule_allocations ADD CONSTRAINT FK_EA52B42132C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE schedule_allocations ADD CONSTRAINT fk_schedule_allocations_specialist_tenant FOREIGN KEY (organization_id, specialist_id) REFERENCES specialists (organization_id, id) ON DELETE RESTRICT');
        $this->addSql("ALTER TABLE schedule_allocations ADD CONSTRAINT exclude_active_schedule_allocation_overlap EXCLUDE USING gist (organization_id WITH =, specialist_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&) WHERE (released_at IS NULL)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE schedule_allocations');
    }
}
