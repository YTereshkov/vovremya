<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create organizations and administrator accounts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE organizations (id UUID NOT NULL, name VARCHAR(160) NOT NULL, timezone VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE administrator_accounts (id UUID NOT NULL, organization_id UUID NOT NULL, email VARCHAR(254) NOT NULL, normalized_email VARCHAR(254) NOT NULL, password_hash VARCHAR(255) NOT NULL, is_enabled BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_administrator_accounts_normalized_email ON administrator_accounts (normalized_email)');
        $this->addSql('CREATE INDEX idx_administrator_accounts_organization_id ON administrator_accounts (organization_id)');
        $this->addSql('ALTER TABLE administrator_accounts ADD CONSTRAINT fk_administrator_accounts_organization_id FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE administrator_accounts DROP CONSTRAINT fk_administrator_accounts_organization_id');
        $this->addSql('DROP TABLE administrator_accounts');
        $this->addSql('DROP TABLE organizations');
    }
}
