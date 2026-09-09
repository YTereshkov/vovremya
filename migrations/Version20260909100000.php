<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track verified state of channel connections';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE channel_connections ADD verified BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('UPDATE channel_connections SET verified = active');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE channel_connections DROP verified');
    }
}
