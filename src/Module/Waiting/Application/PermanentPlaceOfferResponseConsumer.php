<?php

declare(strict_types=1);

namespace App\Module\Waiting\Application;

use App\Module\Communications\Application\NormalizedWebhookEventConsumer;
use App\Module\Communications\Domain\Model\NormalizedWebhookEvent;
use Symfony\Component\Uid\Ulid;

final readonly class PermanentPlaceOfferResponseConsumer implements NormalizedWebhookEventConsumer
{
    public function __construct(private PermanentPlaceOfferService $offers)
    {
    }

    public function consume(NormalizedWebhookEvent $event): void
    {
        $payload = $event->payload();
        $connectionId = $event->channelConnectionId();
        if ('BUTTON' !== ($payload['kind'] ?? null) || !is_string($payload['action'] ?? null) || null === $connectionId) {
            return;
        }
        $action = $payload['action'];
        if (preg_match('/^permanent-place-offer-accept:([0-9a-f-]{36}):([A-Za-z0-9_-]{16,64})$/D', $action, $matches)) {
            if (null !== $id = $this->id($matches[1])) {
                $this->offers->accept($id, $matches[2], $connectionId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            }

            return;
        }
        if (preg_match('/^permanent-place-offer-decline:([0-9a-f-]{36}):([A-Za-z0-9_-]{16,64})$/D', $action, $matches)) {
            if (null !== $id = $this->id($matches[1])) {
                $this->offers->decline($id, $matches[2], $connectionId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            }
        }
    }

    private function id(string $value): ?Ulid
    {
        try {
            return Ulid::fromString($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
