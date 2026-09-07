<?php

declare(strict_types=1);

namespace App\Module\Clients\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class ClientSchemaConstraints
{
    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();
        if (!$schema->hasTable('clients') || !$schema->hasTable('contact_people') || !$schema->hasTable('channel_connections')) {
            return;
        }

        $schema->getTable('contact_people')->addForeignKeyConstraint(
            'clients', ['organization_id', 'client_id'], ['organization_id', 'id'], ['onDelete' => 'CASCADE'], 'fk_contact_people_client_tenant',
        );
        $schema->getTable('channel_connections')->addForeignKeyConstraint(
            'clients', ['organization_id', 'client_id'], ['organization_id', 'id'], ['onDelete' => 'CASCADE'], 'fk_channel_connections_client_tenant',
        );
        $schema->getTable('channel_connections')->addForeignKeyConstraint(
            'contact_people', ['organization_id', 'client_id', 'contact_person_id'], ['organization_id', 'client_id', 'id'], ['onDelete' => 'CASCADE'], 'fk_channel_connections_contact_tenant',
        );
        $schema->getTable('clients')->addForeignKeyConstraint(
            'channel_connections', ['organization_id', 'id', 'primary_channel_id'], ['organization_id', 'client_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_clients_primary_channel_owner',
        );
    }
}
