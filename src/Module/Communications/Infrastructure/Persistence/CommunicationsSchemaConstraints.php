<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class CommunicationsSchemaConstraints
{
    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();
        if (!$schema->hasTable('notification_intents') || !$schema->hasTable('communication_outbox') || !$schema->hasTable('communication_webhook_inbox')) {
            return;
        }

        $schema->getTable('notification_intents')->addForeignKeyConstraint(
            'organizations', ['organization_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_notification_intents_organization',
        );
        if ($schema->hasTable('channel_connections')) {
            $schema->getTable('notification_intents')->addForeignKeyConstraint(
                'channel_connections', ['organization_id', 'recipient_channel_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_notification_intents_channel_tenant',
            );
        }

        $schema->getTable('communication_outbox')->addForeignKeyConstraint(
            'organizations', ['organization_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_communication_outbox_organization',
        );
        $schema->getTable('communication_outbox')->addForeignKeyConstraint(
            'notification_intents', ['organization_id', 'notification_intent_id'], ['organization_id', 'id'], ['onDelete' => 'CASCADE'], 'fk_communication_outbox_intent_tenant',
        );
        if ($schema->hasTable('channel_connections')) {
            $schema->getTable('communication_outbox')->addForeignKeyConstraint(
                'channel_connections', ['organization_id', 'channel_connection_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_communication_outbox_channel_tenant',
            );
        }

        $schema->getTable('communication_webhook_inbox')->addForeignKeyConstraint(
            'organizations', ['organization_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_communication_webhook_organization',
        );
        if ($schema->hasTable('channel_connections')) {
            $schema->getTable('communication_webhook_inbox')->addForeignKeyConstraint(
                'channel_connections', ['organization_id', 'channel_connection_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_communication_webhook_channel_tenant',
            );
        }

        if ($schema->hasTable('communication_normalized_events')) {
            $schema->getTable('communication_normalized_events')->addForeignKeyConstraint(
                'organizations', ['organization_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_normalized_events_organization',
            );
            if ($schema->hasTable('channel_connections')) {
                $schema->getTable('communication_normalized_events')->addForeignKeyConstraint(
                    'channel_connections', ['organization_id', 'channel_connection_id'], ['organization_id', 'id'], ['onDelete' => 'RESTRICT'], 'fk_normalized_events_channel_tenant',
                );
            }
        }

        if ($schema->hasTable('organization_message_templates')) {
            $schema->getTable('organization_message_templates')->addForeignKeyConstraint(
                'organizations', ['organization_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_message_templates_organization',
            );
        }

        if ($schema->hasTable('confirmation_settings')) {
            $schema->getTable('confirmation_settings')->addForeignKeyConstraint(
                'organizations', ['organization_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_confirmation_settings_organization',
            );
        }

        if ($schema->hasTable('administrator_notification_states')) {
            $schema->getTable('administrator_notification_states')->addForeignKeyConstraint(
                'organizations', ['organization_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_admin_notification_states_organization',
            );
            if ($schema->hasTable('administrator_accounts')) {
                $schema->getTable('administrator_notification_states')->addForeignKeyConstraint(
                    'administrator_accounts', ['organization_id', 'administrator_id'], ['organization_id', 'id'], ['onDelete' => 'CASCADE'], 'fk_admin_notification_states_admin_tenant',
                );
            }
        }
    }
}
