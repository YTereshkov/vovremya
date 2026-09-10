<?php

declare(strict_types=1);

namespace App\Module\Waiting\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class WaitingSchemaConstraints
{
    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();
        if (!$schema->hasTable('free_windows')) {
            return;
        }
        $windows = $schema->getTable('free_windows');
        if ($schema->hasTable('appointments')) {
            $windows->addForeignKeyConstraint('appointments', ['organization_id', 'source_appointment_id'], ['organization_id', 'id'], ['onDelete' => 'CASCADE'], 'fk_free_windows_appointment_tenant');
        }
        if ($schema->hasTable('specialists')) {
            $windows->addForeignKeyConstraint('specialists', ['organization_id', 'specialist_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_free_windows_specialist_tenant');
        }
        if ($schema->hasTable('services')) {
            $windows->addForeignKeyConstraint('services', ['organization_id', 'service_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_free_windows_service_tenant');
        }
    }
}
