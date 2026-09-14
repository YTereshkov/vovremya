<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Provider\Telegram;

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

final readonly class TelegramChannelProvider implements ChannelProvider, WebhookEventIdExtractor
{
    private const WEBHOOK_SECRET_HEADER = 'x-telegram-bot-api-secret-token';

    public function __construct(
        private TelegramApiClient $client,
        #[Autowire('%env(TELEGRAM_BOT_TOKEN)%')]
        private string $botToken = '',
    ) {
    }

    public function provider(): CommunicationProvider
    {
        return CommunicationProvider::TELEGRAM;
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(true, false, false, false, true);
    }

    public function send(OutboundMessageRequest $request): ProviderSendResult
    {
        if (CommunicationProvider::TELEGRAM !== $request->provider) {
            throw new \DomainException('Telegram adapter получил сообщение другого канала.');
        }
        OutboundMessage::assertMetadata($request->metadata);
        $token = trim($this->botToken);
        if ('' === $token) {
            throw new \DomainException('Для Telegram не настроен bot token.');
        }
        if (!preg_match('/^-?[1-9][0-9]*$/', $request->recipientAddress)) {
            throw new \DomainException('Адрес получателя Telegram должен быть числовым chat_id.');
        }

        $response = $this->client->sendMessage($token, $this->messagePayload($request));
        $ok = true === ($response->payload['ok'] ?? false);
        if (200 > $response->statusCode || 300 <= $response->statusCode || !$ok) {
            $code = (int) ($response->payload['error_code'] ?? $response->statusCode);
            $message = sprintf('Telegram вернул ошибку %d.', $code);
            if (429 === $code || 500 <= $code || 0 === $code) {
                throw new TelegramTransientApiException($message, $code);
            }
            throw new TelegramPermanentApiException($message, $code);
        }

        $messageId = $response->payload['result']['message_id'] ?? null;
        if (!is_int($messageId) && !is_string($messageId)) {
            throw new TelegramApiException('Telegram не вернул идентификатор сообщения.', $response->statusCode, true);
        }

        return new ProviderSendResult((string) $messageId);
    }

    public function authenticateWebhook(WebhookAuthenticationRequest $request): bool
    {
        if (null === $request->expectedSecretHash || '' === $request->expectedSecretHash) {
            return false;
        }
        $provided = $this->header($request->headers, self::WEBHOOK_SECRET_HEADER);

        return hash_equals($request->expectedSecretHash, hash('sha256', $provided));
    }

    public function parseWebhook(array $payload): array
    {
        $eventId = isset($payload['update_id']) ? (string) $payload['update_id'] : hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $callback = is_array($payload['callback_query'] ?? null) ? $payload['callback_query'] : null;
        if (null !== $callback) {
            return [new WebhookEvent(CommunicationProvider::TELEGRAM, $eventId, [
                'kind' => 'BUTTON',
                'action' => is_string($callback['data'] ?? null) ? $callback['data'] : null,
                'userId' => isset($callback['from']['id']) ? (string) $callback['from']['id'] : null,
                'raw' => $payload,
            ])];
        }

        $message = is_array($payload['message'] ?? null) ? $payload['message'] : null;
        if (null !== $message) {
            $text = is_string($message['text'] ?? null) ? trim($message['text']) : '';
            if (preg_match('/^\/start(?:@[A-Za-z0-9_]+)?\s+([A-Za-z0-9_-]{1,64})$/', $text, $matches)) {
                return [new WebhookEvent(CommunicationProvider::TELEGRAM, $eventId, [
                    'kind' => 'ACTIVATION',
                    'action' => null,
                    'activationToken' => $matches[1],
                    'userId' => isset($message['from']['id']) ? (string) $message['from']['id'] : null,
                    'raw' => $payload,
                ])];
            }

            return [new WebhookEvent(CommunicationProvider::TELEGRAM, $eventId, [
                'kind' => 'TEXT_UNSUPPORTED',
                'action' => null,
                'userId' => isset($message['from']['id']) ? (string) $message['from']['id'] : null,
                'raw' => $payload,
            ])];
        }

        return [new WebhookEvent(CommunicationProvider::TELEGRAM, $eventId, ['kind' => 'UNSUPPORTED', 'action' => null, 'raw' => $payload])];
    }

    public function extractWebhookEventIds(array $payload): array
    {
        return isset($payload['update_id']) ? [(string) $payload['update_id']] : [];
    }

    /** @return array<string, mixed> */
    private function messagePayload(OutboundMessageRequest $request): array
    {
        $payload = ['chat_id' => $request->recipientAddress, 'text' => $request->body];
        if ([] === $request->buttons) {
            return $payload;
        }
        $rows = array_is_list($request->buttons) ? $request->buttons : [$request->buttons];
        $keyboard = [];
        foreach ($rows as $row) {
            $row = is_array($row) && array_is_list($row) ? $row : [$row];
            $normalized = [];
            foreach ($row as $button) {
                if (!is_array($button)) {
                    throw new \InvalidArgumentException('Кнопка Telegram должна быть объектом.');
                }
                $text = trim((string) ($button['text'] ?? $button['label'] ?? ''));
                if ('' === $text) {
                    throw new \InvalidArgumentException('Кнопка Telegram должна содержать text.');
                }
                if (isset($button['url'])) {
                    $url = trim((string) $button['url']);
                    if (!filter_var($url, FILTER_VALIDATE_URL)) {
                        throw new \InvalidArgumentException('Ссылка кнопки Telegram должна быть корректным URL.');
                    }
                    $normalized[] = ['text' => $text, 'url' => $url];
                    continue;
                }
                $action = $button['payload'] ?? $button['action'] ?? $button['id'] ?? null;
                if (!is_scalar($action) || '' === (string) $action || 64 < strlen((string) $action)) {
                    throw new \InvalidArgumentException('Callback кнопки Telegram должен содержать payload длиной до 64 байт.');
                }
                $normalized[] = ['text' => $text, 'callback_data' => (string) $action];
            }
            if ([] !== $normalized) {
                $keyboard[] = $normalized;
            }
        }
        $payload['reply_markup'] = ['inline_keyboard' => $keyboard];

        return $payload;
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
