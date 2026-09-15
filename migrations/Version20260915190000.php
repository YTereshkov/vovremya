<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add customizable confirmation button labels';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE organization_message_templates ADD button_labels JSONB NOT NULL DEFAULT '{}'::jsonb");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization_message_templates DROP button_labels');
    }
}
