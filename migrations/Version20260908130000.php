<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align communication indexes with Doctrine metadata';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER INDEX idx_notification_intents_organization RENAME TO IDX_641E09B532C8A3DE');
        $this->addSql('ALTER INDEX idx_communication_outbox_organization RENAME TO IDX_D16D13B232C8A3DE');
        $this->addSql('ALTER INDEX idx_communication_webhook_organization RENAME TO IDX_59D704E332C8A3DE');
        $this->addSql('DROP INDEX uniq_notification_intents_dedupe');
        $this->addSql('CREATE UNIQUE INDEX uniq_notification_intents_dedupe ON notification_intents (organization_id, dedupe_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_notification_intents_dedupe');
        $this->addSql('CREATE UNIQUE INDEX uniq_notification_intents_dedupe ON notification_intents (organization_id, dedupe_key) WHERE dedupe_key IS NOT NULL');
        $this->addSql('ALTER INDEX IDX_641E09B532C8A3DE RENAME TO idx_notification_intents_organization');
        $this->addSql('ALTER INDEX IDX_D16D13B232C8A3DE RENAME TO idx_communication_outbox_organization');
        $this->addSql('ALTER INDEX IDX_59D704E332C8A3DE RENAME TO idx_communication_webhook_organization');
    }
}
