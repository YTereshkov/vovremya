<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

use App\Module\Communications\Domain\Model\NormalizedWebhookEvent;

final readonly class DeliveryStatusConsumer implements NormalizedWebhookEventConsumer
{
    public function __construct(
        private CommunicationStore $communications,
        private ChannelProviderRegistry $providers,
    ) {
    }

    public function consume(NormalizedWebhookEvent $event): void
    {
        $payload = $event->payload();
        if ('DELIVERY_STATUS' !== ($payload['kind'] ?? null)) {
            return;
        }
        $providerMessageId = $payload['providerMessageId'] ?? null;
        $status = $payload['status'] ?? null;
        if (!is_string($providerMessageId) || '' === trim($providerMessageId) || !is_string($status)) {
            throw new \InvalidArgumentException('Некорректное событие статуса доставки.');
        }
        $message = $this->communications->findOutboundByProviderMessageId($event->provider()->value, $providerMessageId);
        if (null === $message) {
            // A provider callback can race the transaction that stores provider_message_id.
            throw new \RuntimeException('Исходящее сообщение для статуса доставки пока не найдено.');
        }
        if (null === $event->channelConnectionId() || null === $message->channelConnectionId()
            || !$message->channelConnectionId()->equals($event->channelConnectionId())) {
            throw new \DomainException('Статус доставки относится к другому подключению канала.');
        }

        $capabilities = $this->providers->get($event->provider())->capabilities();
        $occurredAt = $this->occurredAt($payload['occurredAt'] ?? null);
        if ('DELIVERED' === $status && $capabilities->supportsDeliveredStatus) {
            $message->markDelivered($occurredAt);
        } elseif ('READ' === $status && $capabilities->supportsReadStatus) {
            $message->markRead($occurredAt);
        } elseif ('FAILED' === $status && $capabilities->supportsDeliveredStatus) {
            $error = is_string($payload['error'] ?? null) ? $payload['error'] : 'Провайдер сообщил об ошибке доставки.';
            $message->markDeliveryFailed($error);
        } else {
            return;
        }
        $this->communications->save($message);
    }

    private function occurredAt(mixed $value): \DateTimeImmutable
    {
        if (null === $value) {
            return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Некорректное время статуса доставки.');
        }
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new \InvalidArgumentException('Некорректное время статуса доставки.');
        }
    }
}
