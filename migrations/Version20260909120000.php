<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add organization message templates and service confirmation override';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE organization_message_templates (id UUID NOT NULL, organization_id UUID NOT NULL, type VARCHAR(24) NOT NULL, body TEXT NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_message_templates_tenant_id ON organization_message_templates (organization_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_message_templates_type ON organization_message_templates (organization_id, type)');
        $this->addSql("ALTER TABLE organization_message_templates ADD CONSTRAINT chk_message_templates_type CHECK (type IN ('CONFIRMATION', 'TRANSFER', 'FREE_WINDOW'))");
        $this->addSql('ALTER TABLE organization_message_templates ADD CONSTRAINT fk_message_templates_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE services ADD confirmation_template TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE services DROP confirmation_template');
        $this->addSql('DROP TABLE organization_message_templates');
    }
}
