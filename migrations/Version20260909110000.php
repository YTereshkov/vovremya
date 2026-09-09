<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align normalized event defaults with Doctrine mapping';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communication_normalized_events ALTER status DROP DEFAULT');
        $this->addSql('ALTER TABLE communication_normalized_events ALTER attempts DROP DEFAULT');
        $this->addSql('ALTER TABLE communication_normalized_events ALTER available_at DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE communication_normalized_events ALTER status SET DEFAULT 'RECEIVED'");
        $this->addSql('ALTER TABLE communication_normalized_events ALTER attempts SET DEFAULT 0');
        $this->addSql('ALTER TABLE communication_normalized_events ALTER available_at SET DEFAULT CURRENT_TIMESTAMP');
    }
}
