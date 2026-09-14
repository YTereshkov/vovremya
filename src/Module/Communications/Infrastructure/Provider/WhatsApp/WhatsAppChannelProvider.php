<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Provider\WhatsApp;

use App\Module\Communications\Application\ChannelCapabilities;
use App\Module\Communications\Application\ChannelProvider;
use App\Module\Communications\Application\OutboundMessageRequest;
use App\Module\Communications\Application\ProviderSendResult;
use App\Module\Communications\Application\WebhookAuthenticationRequest;
use App\Module\Communications\Application\WebhookEvent;
use App\Module\Communications\Application\WebhookEventIdExtractor;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Communications\Domain\Model\OutboundMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class WhatsAppChannelProvider implements ChannelProvider, WebhookEventIdExtractor
{
    public function __construct(
        private WhatsAppApiClient $client,
        #[Autowire('%env(WHATSAPP_ACCESS_TOKEN)%')]
        private string $accessToken = '',
        #[Autowire('%env(WHATSAPP_PHONE_NUMBER_ID)%')]
        private string $phoneNumberId = '',
        #[Autowire('%env(WHATSAPP_APP_SECRET)%')]
        private string $appSecret = '',
    ) {
    }

    public function provider(): CommunicationProvider
    {
        return CommunicationProvider::WHATSAPP;
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(true, false, true, true, true);
    }

    public function send(OutboundMessageRequest $request): ProviderSendResult
    {
        if (CommunicationProvider::WHATSAPP !== $request->provider) {
            throw new \DomainException('WhatsApp adapter получил сообщение другого канала.');
        }
        OutboundMessage::assertMetadata($request->metadata);
        if ('' === trim($this->accessToken) || '' === trim($this->phoneNumberId)) {
            throw new \DomainException('Для WhatsApp не настроены access token и phone number id.');
        }
        if (!ctype_digit($request->recipientAddress) || 5 > strlen($request->recipientAddress) || 20 < strlen($request->recipientAddress)) {
            throw new \DomainException('Адрес WhatsApp должен содержать wa_id получателя.');
        }

        $response = $this->client->sendMessage($this->accessToken, $this->phoneNumberId, $this->messagePayload($request));
        if (200 > $response->statusCode || 300 <= $response->statusCode) {
            $code = (int) ($response->payload['error']['code'] ?? $response->statusCode);
            $message = sprintf('WhatsApp вернул ошибку %d.', $code);
            if (429 === $response->statusCode || 500 <= $response->statusCode || 0 === $response->statusCode) {
                throw new WhatsAppTransientApiException($message, $response->statusCode);
            }
            throw new WhatsAppPermanentApiException($message, $response->statusCode);
        }

        $messageId = $response->payload['messages'][0]['id'] ?? null;
        if (!is_string($messageId) || '' === trim($messageId)) {
            throw new WhatsAppApiException('WhatsApp не вернул идентификатор сообщения.', $response->statusCode, true);
        }

        return new ProviderSendResult($messageId);
    }

    public function authenticateWebhook(WebhookAuthenticationRequest $request): bool
    {
        if (null === $request->expectedSecretHash || '' === $request->expectedSecretHash || '' === $this->appSecret) {
            return false;
        }
        $provided = $this->header($request->headers, 'x-hub-signature-256');
        $expected = 'sha256='.hash_hmac('sha256', $request->rawBody, $this->appSecret);

        return hash_equals($expected, $provided);
    }

    public function parseWebhook(array $payload): array
    {
        $events = [];
        foreach ($this->values($payload) as $value) {
            foreach (($value['messages'] ?? []) as $message) {
                if (!is_array($message)) {
                    continue;
                }
                $eventId = is_string($message['id'] ?? null) ? $message['id'] : hash('sha256', json_encode($message, JSON_THROW_ON_ERROR));
                $userId = isset($message['from']) ? (string) $message['from'] : null;
                $button = is_array($message['interactive']['button_reply'] ?? null) ? $message['interactive']['button_reply'] : null;
                if (null === $button && 'button' === ($message['type'] ?? null) && is_array($message['button'] ?? null)) {
                    $button = $message['button'];
                }
                if (null !== $button) {
                    $events[] = new WebhookEvent(CommunicationProvider::WHATSAPP, $eventId, [
                        'kind' => 'BUTTON',
                        'action' => is_string($button['id'] ?? null) ? $button['id'] : (is_string($button['payload'] ?? null) ? $button['payload'] : null),
                        'userId' => $userId,
                        'raw' => $message,
                    ]);
                    continue;
                }
                $text = is_string($message['text']['body'] ?? null) ? trim($message['text']['body']) : '';
                if (preg_match('/^VOVREMYA_CONNECT\s+([A-Za-z0-9_-]{1,64})$/', $text, $matches)) {
                    $events[] = new WebhookEvent(CommunicationProvider::WHATSAPP, $eventId, [
                        'kind' => 'ACTIVATION', 'action' => null, 'activationToken' => $matches[1], 'userId' => $userId, 'raw' => $message,
                    ]);
                    continue;
                }
                $events[] = new WebhookEvent(CommunicationProvider::WHATSAPP, $eventId, [
                    'kind' => 'TEXT_UNSUPPORTED', 'action' => null, 'userId' => $userId, 'raw' => $message,
                ]);
            }
            foreach (($value['statuses'] ?? []) as $status) {
                if (!is_array($status) || !is_string($status['id'] ?? null) || !is_string($status['status'] ?? null)) {
                    continue;
                }
                $normalized = match (strtolower($status['status'])) {
                    'delivered' => 'DELIVERED',
                    'read' => 'READ',
                    'failed' => 'FAILED',
                    default => null,
                };
                if (null === $normalized) {
                    continue;
                }
                $occurredAt = isset($status['timestamp']) && ctype_digit((string) $status['timestamp'])
                    ? (new \DateTimeImmutable('@'.(string) $status['timestamp']))->format(DATE_ATOM)
                    : null;
                $error = is_array($status['errors'][0] ?? null) ? ($status['errors'][0]['title'] ?? $status['errors'][0]['message'] ?? null) : null;
                $events[] = new WebhookEvent(CommunicationProvider::WHATSAPP, sprintf('%s:%s', $status['id'], strtolower($status['status'])), [
                    'kind' => 'DELIVERY_STATUS',
                    'providerMessageId' => $status['id'],
                    'status' => $normalized,
                    'occurredAt' => $occurredAt,
                    'error' => is_string($error) ? $error : null,
                    'raw' => $status,
                ]);
            }
        }

        return $events;
    }

    public function extractWebhookEventIds(array $payload): array
    {
        return array_values(array_unique(array_map(static fn (WebhookEvent $event): string => $event->externalEventId, $this->parseWebhook($payload))));
    }

    /** @return array<string, mixed> */
    private function messagePayload(OutboundMessageRequest $request): array
    {
        $base = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $request->recipientAddress];
        if ([] === $request->buttons) {
            return $base + ['type' => 'text', 'text' => ['preview_url' => false, 'body' => $request->body]];
        }
        $buttons = [];
        $rows = array_is_list($request->buttons) ? $request->buttons : [$request->buttons];
        foreach ($rows as $row) {
            $row = is_array($row) && array_is_list($row) ? $row : [$row];
            foreach ($row as $button) {
                if (!is_array($button) || isset($button['url'])) {
                    throw new \InvalidArgumentException('WhatsApp поддерживает только reply-кнопки в этом сценарии.');
                }
                $title = trim((string) ($button['text'] ?? $button['label'] ?? ''));
                $action = $button['payload'] ?? $button['action'] ?? $button['id'] ?? null;
                if ('' === $title || 20 < mb_strlen($title) || !is_scalar($action) || '' === (string) $action || 256 < strlen((string) $action)) {
                    throw new \InvalidArgumentException('Reply-кнопка WhatsApp должна содержать title до 20 символов и id до 256 байт.');
                }
                $buttons[] = ['type' => 'reply', 'reply' => ['id' => (string) $action, 'title' => $title]];
            }
        }
        if (3 < count($buttons)) {
            throw new \InvalidArgumentException('WhatsApp допускает не более трёх reply-кнопок.');
        }

        return $base + ['type' => 'interactive', 'interactive' => ['type' => 'button', 'body' => ['text' => $request->body], 'action' => ['buttons' => $buttons]]];
    }

    /** @return list<array<string, mixed>> */
    private function values(array $payload): array
    {
        $values = [];
        foreach (($payload['entry'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            foreach (($entry['changes'] ?? []) as $change) {
                if (is_array($change) && is_array($change['value'] ?? null)) {
                    $values[] = $change['value'];
                }
            }
        }

        return $values;
    }

    /** @param array<string, list<string>> $headers */
    private function header(array $headers, string $expected): string
    {
        foreach ($headers as $name => $values) {
            if (strtolower($name) === $expected) {
                return (string) ($values[0] ?? '');
            }
        }

        return '';
    }
}
