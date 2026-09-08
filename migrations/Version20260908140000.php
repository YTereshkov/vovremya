<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recoverable processing leases to communication outbox and webhook inbox';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communication_outbox ADD processing_started_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_communication_outbox_processing ON communication_outbox (organization_id, status, processing_started_at)');

        $this->addSql('ALTER TABLE communication_webhook_inbox ADD attempts INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE communication_webhook_inbox ALTER attempts DROP DEFAULT');
        $this->addSql('ALTER TABLE communication_webhook_inbox ADD processing_started_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE communication_webhook_inbox DROP CONSTRAINT chk_communication_webhook_status');
        $this->addSql("ALTER TABLE communication_webhook_inbox ADD CONSTRAINT chk_communication_webhook_status CHECK (status IN ('RECEIVED', 'PROCESSING', 'PROCESSED', 'FAILED'))");
        $this->addSql('ALTER TABLE communication_webhook_inbox ADD CONSTRAINT chk_communication_webhook_attempts CHECK (attempts >= 0)');
        $this->addSql('CREATE INDEX idx_communication_webhook_processing ON communication_webhook_inbox (organization_id, status, processing_started_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_communication_webhook_processing');
        $this->addSql('ALTER TABLE communication_webhook_inbox DROP CONSTRAINT chk_communication_webhook_attempts');
        $this->addSql('ALTER TABLE communication_webhook_inbox DROP CONSTRAINT chk_communication_webhook_status');
        $this->addSql("UPDATE communication_webhook_inbox SET status = 'RECEIVED' WHERE status = 'PROCESSING'");
        $this->addSql("ALTER TABLE communication_webhook_inbox ADD CONSTRAINT chk_communication_webhook_status CHECK (status IN ('RECEIVED', 'PROCESSED', 'FAILED'))");
        $this->addSql('ALTER TABLE communication_webhook_inbox DROP processing_started_at');
        $this->addSql('ALTER TABLE communication_webhook_inbox DROP attempts');

        $this->addSql('DROP INDEX idx_communication_outbox_processing');
        $this->addSql('ALTER TABLE communication_outbox DROP processing_started_at');
    }
}
