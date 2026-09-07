<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260907120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tenant-aware composite key to administrator accounts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE administrator_accounts ADD CONSTRAINT uniq_administrator_accounts_organization_id_id UNIQUE (organization_id, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE administrator_accounts DROP CONSTRAINT uniq_administrator_accounts_organization_id_id');
    }
}
