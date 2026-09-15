<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915191000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove transitional default from confirmation button labels';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization_message_templates ALTER button_labels DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE organization_message_templates ALTER button_labels SET DEFAULT '{}'::jsonb");
    }
}
