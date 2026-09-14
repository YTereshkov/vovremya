<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add permanent places, bundle offers, and message template';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE permanent_places (id UUID NOT NULL, organization_id UUID NOT NULL, source_type VARCHAR(12) NOT NULL, source_regular_schedule_id UUID NOT NULL, source_regular_schedule_day_id UUID DEFAULT NULL, specialist_id UUID NOT NULL, service_id UUID NOT NULL, service_name_snapshot VARCHAR(160) NOT NULL, service_default_duration_snapshot SMALLINT NOT NULL, service_minimum_duration_snapshot SMALLINT DEFAULT NULL, service_maximum_duration_snapshot SMALLINT DEFAULT NULL, available_from DATE NOT NULL, status VARCHAR(12) NOT NULL, resulting_regular_schedule_id UUID DEFAULT NULL, closed_reason VARCHAR(48) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, closed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_permanent_places_tenant_id ON permanent_places (organization_id, id)');
        $this->addSql("CREATE UNIQUE INDEX uniq_permanent_places_bundle_source ON permanent_places (organization_id, source_regular_schedule_id) WHERE source_type = 'BUNDLE'");
        $this->addSql("CREATE UNIQUE INDEX uniq_permanent_places_single_source ON permanent_places (organization_id, source_regular_schedule_day_id) WHERE source_type = 'SINGLE'");
        $this->addSql('CREATE INDEX idx_permanent_places_open ON permanent_places (organization_id, status, available_from, created_at)');
        $this->addSql("ALTER TABLE permanent_places ADD CONSTRAINT chk_permanent_places_source CHECK ((source_type = 'SINGLE' AND source_regular_schedule_day_id IS NOT NULL) OR (source_type = 'BUNDLE' AND source_regular_schedule_day_id IS NULL))");
        $this->addSql("ALTER TABLE permanent_places ADD CONSTRAINT chk_permanent_places_status CHECK (status IN ('OPEN', 'CLAIMED', 'CLOSED'))");
        $this->addSql("ALTER TABLE permanent_places ADD CONSTRAINT chk_permanent_places_lifecycle CHECK ((status = 'OPEN' AND resulting_regular_schedule_id IS NULL AND closed_reason IS NULL AND closed_at IS NULL) OR (status = 'CLAIMED' AND resulting_regular_schedule_id IS NOT NULL AND closed_reason = 'OFFER_ACCEPTED' AND closed_at IS NOT NULL) OR (status = 'CLOSED' AND resulting_regular_schedule_id IS NULL AND closed_reason IS NOT NULL AND closed_at IS NOT NULL))");
        $this->addSql('ALTER TABLE permanent_places ADD CONSTRAINT fk_permanent_places_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE permanent_places ADD CONSTRAINT fk_permanent_places_source_schedule_tenant FOREIGN KEY (organization_id, source_regular_schedule_id) REFERENCES regular_schedules (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE permanent_places ADD CONSTRAINT fk_permanent_places_source_day_tenant FOREIGN KEY (organization_id, source_regular_schedule_day_id) REFERENCES regular_schedule_days (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE permanent_places ADD CONSTRAINT fk_permanent_places_specialist_tenant FOREIGN KEY (organization_id, specialist_id) REFERENCES specialists (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE permanent_places ADD CONSTRAINT fk_permanent_places_service_tenant FOREIGN KEY (organization_id, service_id) REFERENCES services (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE permanent_places ADD CONSTRAINT fk_permanent_places_result_schedule_tenant FOREIGN KEY (organization_id, resulting_regular_schedule_id) REFERENCES regular_schedules (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');

        $this->addSql('CREATE TABLE permanent_place_slots (id UUID NOT NULL, organization_id UUID NOT NULL, permanent_place_id UUID NOT NULL, weekday SMALLINT NOT NULL, start_time VARCHAR(5) NOT NULL, duration_minutes SMALLINT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_permanent_place_slots_tenant_id ON permanent_place_slots (organization_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_permanent_place_slots_weekday ON permanent_place_slots (organization_id, permanent_place_id, weekday)');
        $this->addSql('ALTER TABLE permanent_place_slots ADD CONSTRAINT chk_permanent_place_slots_weekday CHECK (weekday BETWEEN 1 AND 7)');
        $this->addSql("ALTER TABLE permanent_place_slots ADD CONSTRAINT chk_permanent_place_slots_time CHECK (start_time ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$')");
        $this->addSql('ALTER TABLE permanent_place_slots ADD CONSTRAINT chk_permanent_place_slots_duration CHECK (duration_minutes BETWEEN 1 AND 1440)');
        $this->addSql('ALTER TABLE permanent_place_slots ADD CONSTRAINT fk_permanent_place_slots_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE permanent_place_slots ADD CONSTRAINT fk_permanent_place_slots_place_tenant FOREIGN KEY (organization_id, permanent_place_id) REFERENCES permanent_places (organization_id, id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE permanent_place_offers (id UUID NOT NULL, organization_id UUID NOT NULL, permanent_place_id UUID NOT NULL, waiting_list_entry_id UUID NOT NULL, client_id UUID NOT NULL, client_name_snapshot VARCHAR(160) NOT NULL, channel_connection_id UUID NOT NULL, status VARCHAR(16) NOT NULL, accept_token_hash VARCHAR(64) NOT NULL, decline_token_hash VARCHAR(64) NOT NULL, resulting_regular_schedule_id UUID DEFAULT NULL, closed_reason VARCHAR(48) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, closed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_permanent_place_offers_tenant_id ON permanent_place_offers (organization_id, id)');
        $this->addSql("CREATE UNIQUE INDEX uniq_permanent_place_offers_active_place ON permanent_place_offers (organization_id, permanent_place_id) WHERE status = 'ACTIVE'");
        $this->addSql('CREATE INDEX idx_permanent_place_offers_client ON permanent_place_offers (organization_id, client_id, created_at)');
        $this->addSql('CREATE INDEX idx_permanent_place_offers_status ON permanent_place_offers (organization_id, status, created_at)');
        $this->addSql("ALTER TABLE permanent_place_offers ADD CONSTRAINT chk_permanent_place_offers_status CHECK (status IN ('ACTIVE', 'ACCEPTED', 'DECLINED', 'CANCELLED'))");
        $this->addSql("ALTER TABLE permanent_place_offers ADD CONSTRAINT chk_permanent_place_offers_lifecycle CHECK ((status = 'ACTIVE' AND closed_at IS NULL AND closed_reason IS NULL AND resulting_regular_schedule_id IS NULL) OR (status = 'ACCEPTED' AND closed_at IS NOT NULL AND closed_reason = 'CLIENT_ACCEPTED' AND resulting_regular_schedule_id IS NOT NULL) OR (status IN ('DECLINED', 'CANCELLED') AND closed_at IS NOT NULL AND closed_reason IS NOT NULL AND resulting_regular_schedule_id IS NULL))");
        $this->addSql('ALTER TABLE permanent_place_offers ADD CONSTRAINT fk_permanent_place_offers_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE permanent_place_offers ADD CONSTRAINT fk_permanent_place_offers_place_tenant FOREIGN KEY (organization_id, permanent_place_id) REFERENCES permanent_places (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE permanent_place_offers ADD CONSTRAINT fk_permanent_place_offers_waiting_tenant FOREIGN KEY (organization_id, waiting_list_entry_id) REFERENCES waiting_list_entries (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE permanent_place_offers ADD CONSTRAINT fk_permanent_place_offers_client_tenant FOREIGN KEY (organization_id, client_id) REFERENCES clients (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE permanent_place_offers ADD CONSTRAINT fk_permanent_place_offers_channel_tenant FOREIGN KEY (organization_id, channel_connection_id) REFERENCES channel_connections (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE permanent_place_offers ADD CONSTRAINT fk_permanent_place_offers_result_schedule_tenant FOREIGN KEY (organization_id, resulting_regular_schedule_id) REFERENCES regular_schedules (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');

        $this->addSql('ALTER TABLE organization_message_templates DROP CONSTRAINT chk_message_templates_type');
        $this->addSql("ALTER TABLE organization_message_templates ADD CONSTRAINT chk_message_templates_type CHECK (type IN ('CONFIRMATION', 'TRANSFER', 'FREE_WINDOW', 'PERMANENT_PLACE'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM organization_message_templates WHERE type = 'PERMANENT_PLACE'");
        $this->addSql('ALTER TABLE organization_message_templates DROP CONSTRAINT chk_message_templates_type');
        $this->addSql("ALTER TABLE organization_message_templates ADD CONSTRAINT chk_message_templates_type CHECK (type IN ('CONFIRMATION', 'TRANSFER', 'FREE_WINDOW'))");
        $this->addSql('DROP TABLE permanent_place_offers');
        $this->addSql('DROP TABLE permanent_place_slots');
        $this->addSql('DROP TABLE permanent_places');
    }
}
