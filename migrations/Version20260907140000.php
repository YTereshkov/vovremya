<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260907140000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add specialists, weekly hours and tenant-owned additional working days'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE specialists (weekly_hours JSONB NOT NULL, administrator_id UUID DEFAULT NULL, name VARCHAR(160) NOT NULL, specialization VARCHAR(100) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, id UUID NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A11CDE4432C8A3DE ON specialists (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_specialists_tenant_id ON specialists (organization_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_specialists_administrator ON specialists (organization_id, administrator_id)');
        $this->addSql('ALTER TABLE specialists ADD CONSTRAINT FK_A11CDE4432C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE specialists ADD CONSTRAINT fk_specialists_administrator_tenant FOREIGN KEY (organization_id, administrator_id) REFERENCES administrator_accounts (organization_id, id) ON DELETE RESTRICT');
        $this->addSql('CREATE TABLE additional_working_days (specialist_id UUID NOT NULL, date DATE NOT NULL, work JSONB NOT NULL, id UUID NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C972016132C8A3DE ON additional_working_days (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_additional_days_tenant_id ON additional_working_days (organization_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_additional_days_date ON additional_working_days (organization_id, specialist_id, date)');
        $this->addSql('CREATE INDEX IDX_C972016132C8A3DE7B100C1A ON additional_working_days (organization_id, specialist_id)');
        $this->addSql('ALTER TABLE additional_working_days ADD CONSTRAINT FK_C972016132C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE additional_working_days ADD CONSTRAINT fk_additional_days_specialist_tenant FOREIGN KEY (organization_id, specialist_id) REFERENCES specialists (organization_id, id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE additional_working_days');
        $this->addSql('DROP TABLE specialists');
    }
}
