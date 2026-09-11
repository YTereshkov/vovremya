<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reserving FreeWindow offers with explicit lifecycle';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE free_window_offers (id UUID NOT NULL, organization_id UUID NOT NULL, free_window_id UUID NOT NULL, client_id UUID NOT NULL, client_name_snapshot VARCHAR(160) NOT NULL, channel_connection_id UUID NOT NULL, target_type VARCHAR(20) NOT NULL, candidate_appointment_id UUID DEFAULT NULL, waiting_list_entry_id UUID DEFAULT NULL, service_id UUID NOT NULL, service_name_snapshot VARCHAR(160) NOT NULL, service_default_duration_snapshot SMALLINT NOT NULL, service_minimum_duration_snapshot SMALLINT DEFAULT NULL, service_maximum_duration_snapshot SMALLINT DEFAULT NULL, appointment_duration_minutes SMALLINT NOT NULL, status VARCHAR(16) NOT NULL, accept_token_hash VARCHAR(64) NOT NULL, decline_token_hash VARCHAR(64) NOT NULL, resulting_appointment_id UUID DEFAULT NULL, closed_reason VARCHAR(48) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, closed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_free_window_offers_tenant_id ON free_window_offers (organization_id, id)');
        $this->addSql("CREATE UNIQUE INDEX uniq_free_window_offers_active_window ON free_window_offers (organization_id, free_window_id) WHERE status = 'ACTIVE'");
        $this->addSql('CREATE INDEX idx_free_window_offers_client ON free_window_offers (organization_id, client_id, created_at)');
        $this->addSql('CREATE INDEX idx_free_window_offers_status ON free_window_offers (organization_id, status, created_at)');
        $this->addSql("ALTER TABLE free_window_offers ADD CONSTRAINT chk_free_window_offers_status CHECK (status IN ('ACTIVE', 'ACCEPTED', 'DECLINED', 'CANCELLED'))");
        $this->addSql("ALTER TABLE free_window_offers ADD CONSTRAINT chk_free_window_offers_target CHECK ((target_type = 'MOVE_EARLIER' AND candidate_appointment_id IS NOT NULL AND waiting_list_entry_id IS NULL) OR (target_type = 'WAITING_LIST' AND candidate_appointment_id IS NULL AND waiting_list_entry_id IS NOT NULL))");
        $this->addSql('ALTER TABLE free_window_offers ADD CONSTRAINT chk_free_window_offers_duration CHECK (appointment_duration_minutes > 0 AND service_default_duration_snapshot > 0 AND (service_minimum_duration_snapshot IS NULL OR appointment_duration_minutes >= service_minimum_duration_snapshot) AND (service_maximum_duration_snapshot IS NULL OR appointment_duration_minutes <= service_maximum_duration_snapshot))');
        $this->addSql("ALTER TABLE free_window_offers ADD CONSTRAINT chk_free_window_offers_lifecycle CHECK ((status = 'ACTIVE' AND closed_at IS NULL AND closed_reason IS NULL AND resulting_appointment_id IS NULL) OR (status = 'ACCEPTED' AND closed_at IS NOT NULL AND closed_reason = 'CLIENT_ACCEPTED' AND resulting_appointment_id IS NOT NULL) OR (status IN ('DECLINED', 'CANCELLED') AND closed_at IS NOT NULL AND closed_reason IS NOT NULL AND resulting_appointment_id IS NULL))");
        $this->addSql('ALTER TABLE free_window_offers ADD CONSTRAINT fk_free_window_offers_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE free_window_offers ADD CONSTRAINT fk_free_window_offers_window_tenant FOREIGN KEY (organization_id, free_window_id) REFERENCES free_windows (organization_id, id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE free_window_offers ADD CONSTRAINT fk_free_window_offers_client_tenant FOREIGN KEY (organization_id, client_id) REFERENCES clients (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE free_window_offers ADD CONSTRAINT fk_free_window_offers_channel_tenant FOREIGN KEY (organization_id, channel_connection_id) REFERENCES channel_connections (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE free_window_offers ADD CONSTRAINT fk_free_window_offers_candidate_tenant FOREIGN KEY (organization_id, candidate_appointment_id) REFERENCES appointments (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE free_window_offers ADD CONSTRAINT fk_free_window_offers_waiting_tenant FOREIGN KEY (organization_id, waiting_list_entry_id) REFERENCES waiting_list_entries (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE free_window_offers ADD CONSTRAINT fk_free_window_offers_service_tenant FOREIGN KEY (organization_id, service_id) REFERENCES services (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE free_window_offers ADD CONSTRAINT fk_free_window_offers_result_tenant FOREIGN KEY (organization_id, resulting_appointment_id) REFERENCES appointments (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE free_window_offers');
    }
}
