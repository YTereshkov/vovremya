<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class SchedulingSchemaConstraints
{
    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();
        if (!$schema->hasTable('schedule_allocations') || !$schema->hasTable('specialists')) {
            return;
        }

        $table = $schema->getTable('schedule_allocations');
        $table->addForeignKeyConstraint(
            'specialists',
            ['organization_id', 'specialist_id'],
            ['organization_id', 'id'],
            ['onDelete' => 'RESTRICT'],
            'fk_schedule_allocations_specialist_tenant',
        );

        // DBAL introspects the exclusion constraint through its backing index
        // and omits the range expression. Mirror that shape for schema comparisons.
        $table->addIndex(
            ['organization_id', 'specialist_id'],
            'exclude_active_schedule_allocation_overlap',
            [],
            ['where' => '(released_at IS NULL)'],
        );
    }
}
