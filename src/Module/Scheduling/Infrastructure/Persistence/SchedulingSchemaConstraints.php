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
            if ($schema->hasTable('regular_schedules')) {
                $appointments->addForeignKeyConstraint('regular_schedules', ['organization_id', 'regular_schedule_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_appointments_regular_schedule_tenant');
                $appointments->addForeignKeyConstraint('regular_schedule_days', ['organization_id', 'regular_schedule_day_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_appointments_regular_day_tenant');
                $appointments->addUniqueIndex(['organization_id', 'regular_schedule_id', 'occurrence_date'], 'uniq_appointment_regular_occurrence', ['where' => "((regular_schedule_id IS NOT NULL) AND ((planning_status)::text = 'PLANNED'::text))"]);
            }
        }

        if ($schema->hasTable('appointment_events')) {
            $events = $schema->getTable('appointment_events');
            $events->addForeignKeyConstraint('appointments', ['organization_id', 'appointment_id'], ['organization_id', 'id'], ['onDelete' => 'CASCADE'], 'fk_appointment_events_appointment_tenant');
            $events->addForeignKeyConstraint('administrator_accounts', ['organization_id', 'actor_administrator_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_appointment_events_actor_tenant');
        }

        if ($schema->hasTable('appointment_confirmation_requests')) {
            $requests = $schema->getTable('appointment_confirmation_requests');
            $requests->addForeignKeyConstraint('appointments', ['organization_id', 'appointment_id'], ['organization_id', 'id'], ['onDelete' => 'CASCADE'], 'fk_confirmation_requests_appointment_tenant');
            if ($schema->hasTable('channel_connections')) {
                $requests->addForeignKeyConstraint('channel_connections', ['organization_id', 'channel_connection_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_confirmation_requests_channel_tenant');
            }
        }

        if ($schema->hasTable('appointment_confirmation_actions')) {
            $schema->getTable('appointment_confirmation_actions')->addForeignKeyConstraint(
                'appointment_confirmation_requests', ['organization_id', 'confirmation_request_id'], ['organization_id', 'id'], ['onDelete' => 'CASCADE'], 'fk_confirmation_actions_request_tenant',
            );
        }

        if ($schema->hasTable('regular_schedules')) {
            $schedules = $schema->getTable('regular_schedules');
            $schedules->addForeignKeyConstraint('specialists', ['organization_id', 'specialist_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_regular_schedules_specialist_tenant');
            $schedules->addForeignKeyConstraint('clients', ['organization_id', 'client_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_regular_schedules_client_tenant');
            $schedules->addForeignKeyConstraint('services', ['organization_id', 'service_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_regular_schedules_service_tenant');
        }

        if ($schema->hasTable('regular_schedule_days')) {
            $schema->getTable('regular_schedule_days')->addForeignKeyConstraint('regular_schedules', ['organization_id', 'regular_schedule_id'], ['organization_id', 'id'], ['onDelete' => 'CASCADE'], 'fk_regular_schedule_days_schedule_tenant');
        }

        if ($schema->hasTable('schedule_generation_issues')) {
            $issues = $schema->getTable('schedule_generation_issues');
            $issues->addForeignKeyConstraint('regular_schedules', ['organization_id', 'regular_schedule_id'], ['organization_id', 'id'], ['onDelete' => 'CASCADE'], 'fk_schedule_generation_issues_schedule_tenant');
            $issues->addForeignKeyConstraint('regular_schedule_days', ['organization_id', 'regular_schedule_day_id'], ['organization_id', 'id'], ['onDelete' => 'CASCADE'], 'fk_schedule_generation_issues_day_tenant');
        }
    }
}
