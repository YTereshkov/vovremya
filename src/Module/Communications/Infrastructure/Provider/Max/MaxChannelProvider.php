<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Provider\Max;

use App\Module\Communications\Application\ChannelCapabilities;
use App\Module\Communications\Application\ChannelProvider;
use App\Module\Communications\Application\OutboundMessageRequest;
use App\Module\Communications\Application\ProviderSendResult;
use App\Module\Communications\Application\WebhookAuthenticationRequest;
use App\Module\Communications\Application\WebhookEvent;
use App\Module\Communications\Application\WebhookEventIdExtractor;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class MaxChannelProvider implements ChannelProvider, WebhookEventIdExtractor
{
    private const WEBHOOK_SECRET_HEADER = 'x-max-bot-api-secret';

    public function __construct(
        private MaxApiClient $client,
        #[Autowire('%env(MAX_BOT_TOKEN)%')]
        private string $defaultAccessToken = '',
    ) {
    }

    public function provider(): CommunicationProvider
    {
        return CommunicationProvider::MAX;
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            supportsButtons: true,
            // The generic communications contract has no edit operation yet.
            supportsMessageEdit: false,
            supportsDeliveredStatus: false,
            supportsReadStatus: false,
            supportsDeepLink: true,
        );
    }

    public function send(OutboundMessageRequest $request): ProviderSendResult
    {
        if (CommunicationProvider::MAX !== $request->provider) {
            throw new \DomainException('MAX adapter получил сообщение другого канала.');
        }

        \App\Module\Communications\Domain\Model\OutboundMessage::assertMetadata($request->metadata);
        $accessToken = trim($this->defaultAccessToken);
        if ('' === $accessToken) {
            throw new \DomainException('Для MAX не настроен access token.');
        }
        if (!ctype_digit($request->recipientAddress) || '' === ltrim($request->recipientAddress, '0')) {
            throw new \DomainException('Адрес получателя MAX должен быть числовым user_id.');
        }

        $response = $this->client->sendMessage(
            $accessToken,
            $request->recipientAddress,
            $this->messagePayload($request),
            $request->idempotencyKey,
            null,
        );
        if (200 > $response->statusCode || 300 <= $response->statusCode) {
            $transient = 429 === $response->statusCode || 500 <= $response->statusCode || 0 === $response->statusCode;
            $message = 401 === $response->statusCode
                ? 'MAX отклонил access token.'
                : sprintf('MAX вернул HTTP %d.', $response->statusCode);
            if ($transient) {
                throw new MaxTransientApiException($message, $response->statusCode);
            }

            throw new MaxPermanentApiException($message, $response->statusCode);
        }

        $message = $response->payload['message'] ?? null;
        $providerMessageId = is_array($message) && isset($message['body']['mid'])
            ? (string) $message['body']['mid']
            : (is_array($message) && isset($message['id']) ? (string) $message['id'] : null);

        return new ProviderSendResult($providerMessageId);
    }

    public function authenticateWebhook(WebhookAuthenticationRequest $request): bool
    {
        if (null === $request->expectedSecretHash || '' === $request->expectedSecretHash) {
            return false;
        }

        $provided = '';
        foreach ($request->headers as $name => $values) {
            if (strtolower($name) === self::WEBHOOK_SECRET_HEADER) {
                $provided = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
                break;
            }
        }

        return hash_equals($request->expectedSecretHash, hash('sha256', $provided));
    }

    public function parseWebhook(array $payload): array
    {
        $updates = isset($payload['updates']) && is_array($payload['updates']) ? $payload['updates'] : [$payload];
        $events = [];
        foreach ($updates as $update) {
            if (!is_array($update)) {
                continue;
            }
            $type = (string) ($update['update_type'] ?? $update['type'] ?? '');
            $callback = is_array($update['callback'] ?? null) ? $update['callback'] : [];
            $eventId = 'message_callback' === $type
                ? (string) ($callback['callback_id'] ?? $update['update_id'] ?? $update['event_id'] ?? '')
                : (string) ($update['update_id'] ?? $update['event_id'] ?? '');
            $eventId = '' !== $eventId ? $eventId : hash('sha256', json_encode($update, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $user = is_array($update['user'] ?? null)
                ? $update['user']
                : (is_array($callback['user'] ?? null) ? $callback['user'] : []);

            if ('message_callback' === $type) {
                $action = $callback['payload'] ?? $callback['action'] ?? null;
                $events[] = new WebhookEvent(CommunicationProvider::MAX, $eventId, [
                    'kind' => 'BUTTON',
                    'action' => is_scalar($action) ? (string) $action : null,
                    'userId' => isset($user['user_id']) ? (string) $user['user_id'] : null,
                    'raw' => $update,
                ]);
                continue;
            }

            if ('message_created' === $type || 'message' === $type) {
                $events[] = new WebhookEvent(CommunicationProvider::MAX, $eventId, [
                    'kind' => 'TEXT_UNSUPPORTED',
                    'action' => null,
                    'userId' => isset($user['user_id']) ? (string) $user['user_id'] : null,
                    'raw' => $update,
                ]);
                continue;
            }

            if ('bot_started' === $type) {
                $events[] = new WebhookEvent(CommunicationProvider::MAX, $eventId, [
                    'kind' => 'ACTIVATION',
                    'action' => null,
                    'activationToken' => is_string($update['payload'] ?? null) ? $update['payload'] : null,
                    'userId' => isset($user['user_id']) ? (string) $user['user_id'] : null,
                    'raw' => $update,
                ]);
                continue;
            }

            $events[] = new WebhookEvent(CommunicationProvider::MAX, $eventId, [
                'kind' => 'UNSUPPORTED',
                'action' => null,
                'raw' => $update,
            ]);
        }

        return $events;
    }

    public function extractWebhookEventIds(array $payload): array
    {
        $updates = isset($payload['updates']) && is_array($payload['updates']) ? $payload['updates'] : [$payload];
        $ids = [];
        foreach ($updates as $update) {
            if (!is_array($update)) {
                continue;
            }
            $callback = is_array($update['callback'] ?? null) ? $update['callback'] : [];
            $type = (string) ($update['update_type'] ?? $update['type'] ?? '');
            $id = 'message_callback' === $type
                ? (string) ($callback['callback_id'] ?? $update['update_id'] ?? $update['event_id'] ?? '')
                : (string) ($update['update_id'] ?? $update['event_id'] ?? '');
            if ('' !== $id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return array<string, mixed> */
    private function messagePayload(OutboundMessageRequest $request): array
    {
        $payload = ['text' => $request->body];
        if ([] !== $request->buttons) {
            $rows = array_is_list($request->buttons) ? $request->buttons : [$request->buttons];
            $buttons = [];
            foreach ($rows as $row) {
                $row = is_array($row) && array_is_list($row) ? $row : [$row];
                $normalizedRow = [];
                foreach ($row as $button) {
                    if (!is_array($button)) {
                        throw new \InvalidArgumentException('Кнопка MAX должна быть объектом.');
                    }
                    $text = trim((string) ($button['text'] ?? $button['label'] ?? ''));
                    $buttonType = strtolower((string) ($button['type'] ?? (isset($button['url']) ? 'link' : 'callback')));
                    if ('' === $text) {
                        throw new \InvalidArgumentException('Кнопка MAX должна содержать text.');
                    }
                    if ('link' === $buttonType) {
                        $url = trim((string) ($button['url'] ?? ''));
                        if ('' === $url || !filter_var($url, FILTER_VALIDATE_URL)) {
                            throw new \InvalidArgumentException('Ссылка кнопки MAX должна быть корректным URL.');
                        }
                        $normalizedRow[] = ['type' => 'link', 'text' => $text, 'url' => $url];
                        continue;
                    }
                    $action = $button['payload'] ?? $button['action'] ?? $button['id'] ?? null;
                    if ('callback' !== $buttonType || null === $action || !is_scalar($action)) {
                        throw new \InvalidArgumentException('Кнопка MAX должна содержать text и payload.');
                    }
                    $normalizedRow[] = ['type' => 'callback', 'text' => $text, 'payload' => (string) $action];
                }
                if ([] !== $normalizedRow) {
                    $buttons[] = $normalizedRow;
                }
            }
            if ([] !== $buttons) {
                $payload['attachments'] = [[
                    'type' => 'inline_keyboard',
                    'payload' => ['buttons' => $buttons],
                ]];
            }
        }

        return $payload;
    }
}
