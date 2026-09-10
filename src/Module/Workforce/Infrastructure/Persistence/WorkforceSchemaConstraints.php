<?php

declare(strict_types=1);

namespace App\Module\Workforce\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class WorkforceSchemaConstraints
{
    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();
        // Scalar references avoid ORM associations to non-primary composite keys.
        // Keep the actual tenant foreign keys in schema comparisons and migrations.
        if ($schema->hasTable('specialists') && $schema->hasTable('administrator_accounts')) {
            $schema->getTable('specialists')->addForeignKeyConstraint('administrator_accounts',
                ['organization_id', 'administrator_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_specialists_administrator_tenant');
        }
        if ($schema->hasTable('additional_working_days') && $schema->hasTable('specialists')) {
            $schema->getTable('additional_working_days')->addForeignKeyConstraint('specialists',
                ['organization_id', 'specialist_id'], ['organization_id', 'id'], ['onDelete' => 'CASCADE'], 'fk_additional_days_specialist_tenant');
        }
        if ($schema->hasTable('specialist_absences') && $schema->hasTable('specialists')) {
            $schema->getTable('specialist_absences')->addForeignKeyConstraint('specialists',
                ['organization_id', 'specialist_id'], ['organization_id', 'id'], ['onDelete' => 'CASCADE'], 'fk_specialist_absences_specialist_tenant');
        }
    }
}
