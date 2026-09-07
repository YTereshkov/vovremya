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
        if ($schema->hasTable('schedule_allocations') && $schema->hasTable('specialists')) {
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

        if ($schema->hasTable('appointments')) {
            $appointments = $schema->getTable('appointments');
            $appointments->addForeignKeyConstraint('specialists', ['organization_id', 'specialist_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_appointments_specialist_tenant');
            $appointments->addForeignKeyConstraint('clients', ['organization_id', 'client_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_appointments_client_tenant');
            $appointments->addForeignKeyConstraint('services', ['organization_id', 'service_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_appointments_service_tenant');
        }

        if ($schema->hasTable('appointment_events')) {
            $events = $schema->getTable('appointment_events');
            $events->addForeignKeyConstraint('appointments', ['organization_id', 'appointment_id'], ['organization_id', 'id'], ['onDelete' => 'CASCADE'], 'fk_appointment_events_appointment_tenant');
            $events->addForeignKeyConstraint('administrator_accounts', ['organization_id', 'actor_administrator_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_appointment_events_actor_tenant');
        }
    }
}
