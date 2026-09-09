<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add processing lifecycle to normalized communication events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE communication_normalized_events ADD status VARCHAR(16) NOT NULL DEFAULT 'RECEIVED'");
        $this->addSql('ALTER TABLE communication_normalized_events ADD attempts INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE communication_normalized_events ADD available_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP');
        $this->addSql('ALTER TABLE communication_normalized_events ADD processing_started_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE communication_normalized_events ADD processed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE communication_normalized_events ADD processing_error VARCHAR(500) DEFAULT NULL');
        $this->addSql("ALTER TABLE communication_normalized_events ADD CONSTRAINT chk_normalized_events_status CHECK (status IN ('RECEIVED', 'PROCESSING', 'PROCESSED', 'FAILED'))");
        $this->addSql('ALTER TABLE communication_normalized_events ADD CONSTRAINT chk_normalized_events_attempts CHECK (attempts >= 0)');
        $this->addSql('CREATE INDEX idx_normalized_events_pending ON communication_normalized_events (organization_id, status, available_at)');
        $this->addSql('CREATE INDEX idx_normalized_events_processing ON communication_normalized_events (organization_id, status, processing_started_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_normalized_events_processing');
        $this->addSql('DROP INDEX IF EXISTS idx_normalized_events_pending');
        $this->addSql('ALTER TABLE communication_normalized_events DROP CONSTRAINT IF EXISTS chk_normalized_events_attempts');
        $this->addSql('ALTER TABLE communication_normalized_events DROP CONSTRAINT IF EXISTS chk_normalized_events_status');
        $this->addSql('ALTER TABLE communication_normalized_events DROP processing_error');
        $this->addSql('ALTER TABLE communication_normalized_events DROP processed_at');
        $this->addSql('ALTER TABLE communication_normalized_events DROP processing_started_at');
        $this->addSql('ALTER TABLE communication_normalized_events DROP available_at');
        $this->addSql('ALTER TABLE communication_normalized_events DROP attempts');
        $this->addSql('ALTER TABLE communication_normalized_events DROP status');
    }
}
