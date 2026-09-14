<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

use App\Module\Clients\Application\ChannelConnectionResolver;
use App\Module\Communications\Domain\Model\NormalizedWebhookEvent;

final readonly class UnsupportedTextResponseConsumer implements NormalizedWebhookEventConsumer
{
    public function __construct(
        private ChannelConnectionResolver $connections,
        private NotificationOutbox $outbox,
    ) {
    }

    public function consume(NormalizedWebhookEvent $event): void
    {
        $payload = $event->payload();
        if ('TEXT_UNSUPPORTED' !== ($payload['kind'] ?? null) || null === $event->channelConnectionId()) {
            return;
        }
        $connection = $this->connections->findChannelForTenant($event->channelConnectionId());
        $userId = $payload['userId'] ?? null;
        if (null === $connection || !$connection->isActive() || $connection->provider() !== $event->provider()->value
            || !is_string($userId) || !hash_equals($connection->address(), $userId)) {
            return;
        }

        $this->outbox->queue(
            'UNSUPPORTED_TEXT_RESPONSE',
            $connection->id(),
            $event->provider(),
            $connection->address(),
            'Для работы с расписанием используйте кнопки в сообщении.',
            payload: ['webhookEventId' => $event->externalEventId()],
            metadata: ['source' => 'UNSUPPORTED_TEXT_RESPONSE'],
            dedupeKey: 'unsupported-text:'.$event->externalEventId(),
        );
    }
}
