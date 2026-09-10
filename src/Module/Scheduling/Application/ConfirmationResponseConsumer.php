<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Communications\Application\NormalizedWebhookEventConsumer;
use App\Module\Communications\Domain\Model\NormalizedWebhookEvent;
use Symfony\Component\Uid\Ulid;

final readonly class ConfirmationResponseConsumer implements NormalizedWebhookEventConsumer
{
    public function __construct(private ConfirmationActionProcessor $processor)
    {
    }

    public function consume(NormalizedWebhookEvent $event): void
    {
        $payload = $event->payload();
        if ('BUTTON' !== ($payload['kind'] ?? null) || !is_string($payload['action'] ?? null) || null === $event->channelConnectionId()) {
            return;
        }
        if (!preg_match('/^confirmation:([0-9a-f-]{36}):([A-Za-z0-9_-]{16,64})$/D', $payload['action'], $matches)) {
            return;
        }
        try {
            $actionId = Ulid::fromString($matches[1]);
        } catch (\InvalidArgumentException) {
            return;
        }
        $this->processor->consume(
            $actionId,
            $matches[2],
            $event->channelConnectionId(),
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }
}
