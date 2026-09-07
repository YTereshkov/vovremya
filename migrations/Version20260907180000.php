<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260907180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add services, clients, contact people and channel metadata';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE services (id UUID NOT NULL, organization_id UUID NOT NULL, name VARCHAR(160) NOT NULL, default_duration_minutes SMALLINT NOT NULL, minimum_duration_minutes SMALLINT DEFAULT NULL, maximum_duration_minutes SMALLINT DEFAULT NULL, deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_7332E16932C8A3DE ON services (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_services_tenant_id ON services (organization_id, id)');
        $this->addSql('CREATE INDEX idx_services_active_name ON services (organization_id, name, id) WHERE deleted_at IS NULL');
        $this->addSql('ALTER TABLE services ADD CONSTRAINT FK_7332E16932C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');

        $this->addSql('CREATE TABLE clients (id UUID NOT NULL, organization_id UUID NOT NULL, name VARCHAR(160) NOT NULL, client_type VARCHAR(16) NOT NULL, phone VARCHAR(32) DEFAULT NULL, note VARCHAR(300) DEFAULT NULL, primary_channel_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_C82E7432C8A3DE ON clients (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_clients_tenant_id ON clients (organization_id, id)');
        $this->addSql('CREATE INDEX idx_clients_tenant_name ON clients (organization_id, name, id)');
        $this->addSql('ALTER TABLE clients ADD CONSTRAINT FK_C82E74EE32C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');

        $this->addSql('CREATE TABLE contact_people (id UUID NOT NULL, organization_id UUID NOT NULL, client_id UUID NOT NULL, name VARCHAR(160) NOT NULL, phone VARCHAR(32) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_B8845DA732C8A3DE ON contact_people (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_contact_people_tenant_id ON contact_people (organization_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_contact_people_owner_id ON contact_people (organization_id, client_id, id)');
        $this->addSql('CREATE INDEX idx_contact_people_owner ON contact_people (organization_id, client_id)');
        $this->addSql('ALTER TABLE contact_people ADD CONSTRAINT FK_993825D732C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contact_people ADD CONSTRAINT fk_contact_people_client_tenant FOREIGN KEY (organization_id, client_id) REFERENCES clients (organization_id, id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE channel_connections (id UUID NOT NULL, organization_id UUID NOT NULL, client_id UUID NOT NULL, contact_person_id UUID DEFAULT NULL, provider VARCHAR(16) NOT NULL, address VARCHAR(254) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_A62C033432C8A3DE ON channel_connections (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_channel_connections_tenant_id ON channel_connections (organization_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_channel_connections_owner_id ON channel_connections (organization_id, client_id, id)');
        $this->addSql('CREATE INDEX idx_channel_connections_owner ON channel_connections (organization_id, client_id)');
        $this->addSql('ALTER TABLE channel_connections ADD CONSTRAINT FK_8E731E4432C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE channel_connections ADD CONSTRAINT fk_channel_connections_client_tenant FOREIGN KEY (organization_id, client_id) REFERENCES clients (organization_id, id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE channel_connections ADD CONSTRAINT fk_channel_connections_contact_tenant FOREIGN KEY (organization_id, client_id, contact_person_id) REFERENCES contact_people (organization_id, client_id, id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE clients ADD CONSTRAINT fk_clients_primary_channel_owner FOREIGN KEY (organization_id, id, primary_channel_id) REFERENCES channel_connections (organization_id, client_id, id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clients DROP CONSTRAINT fk_clients_primary_channel_owner');
        $this->addSql('DROP TABLE channel_connections');
        $this->addSql('DROP TABLE contact_people');
        $this->addSql('DROP TABLE clients');
        $this->addSql('DROP TABLE services');
    }
}
