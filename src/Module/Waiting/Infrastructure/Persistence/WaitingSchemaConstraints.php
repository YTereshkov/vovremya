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
        if ($schema->hasTable('free_windows')) {
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
        if ($schema->hasTable('waiting_list_entries')) {
            $entries = $schema->getTable('waiting_list_entries');
            if ($schema->hasTable('clients')) {
                $entries->addForeignKeyConstraint('clients', ['organization_id', 'client_id'], ['organization_id', 'id'], ['onDelete' => 'CASCADE'], 'fk_waiting_entries_client_tenant');
            }
            if ($schema->hasTable('services')) {
                $entries->addForeignKeyConstraint('services', ['organization_id', 'service_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_waiting_entries_service_tenant');
            }
            if ($schema->hasTable('specialists')) {
                $entries->addForeignKeyConstraint('specialists', ['organization_id', 'specialist_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_waiting_entries_specialist_tenant');
            }
        }
        if ($schema->hasTable('waiting_list_availability') && $schema->hasTable('waiting_list_entries')) {
            $schema->getTable('waiting_list_availability')->addForeignKeyConstraint(
                'waiting_list_entries',
                ['organization_id', 'waiting_list_entry_id'],
                ['organization_id', 'id'],
                ['onDelete' => 'CASCADE'],
                'fk_waiting_availability_entry_tenant',
            );
        }
        if ($schema->hasTable('free_window_offers')) {
            $offers = $schema->getTable('free_window_offers');
            $offers->addForeignKeyConstraint('free_windows', ['organization_id', 'free_window_id'], ['organization_id', 'id'], ['onDelete' => 'CASCADE'], 'fk_free_window_offers_window_tenant');
            $offers->addForeignKeyConstraint('clients', ['organization_id', 'client_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_free_window_offers_client_tenant');
            $offers->addForeignKeyConstraint('channel_connections', ['organization_id', 'channel_connection_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_free_window_offers_channel_tenant');
            $offers->addForeignKeyConstraint('appointments', ['organization_id', 'candidate_appointment_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_free_window_offers_candidate_tenant');
            $offers->addForeignKeyConstraint('waiting_list_entries', ['organization_id', 'waiting_list_entry_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_free_window_offers_waiting_tenant');
            $offers->addForeignKeyConstraint('services', ['organization_id', 'service_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_free_window_offers_service_tenant');
            $offers->addForeignKeyConstraint('appointments', ['organization_id', 'resulting_appointment_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_free_window_offers_result_tenant');
            $offers->addUniqueIndex(['organization_id', 'free_window_id'], 'uniq_free_window_offers_active_window', ['where' => "((status)::text = 'ACTIVE'::text)"]);
        }
    }
}
