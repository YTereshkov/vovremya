<?php

declare(strict_types=1);

namespace App\Tests\Module\Communications\Infrastructure\Provider\Telegram;

use App\Module\Communications\Application\OutboundMessageRequest;
use App\Module\Communications\Application\WebhookAuthenticationRequest;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Communications\Infrastructure\Provider\Telegram\TelegramApiClient;
use App\Module\Communications\Infrastructure\Provider\Telegram\TelegramApiResponse;
use App\Module\Communications\Infrastructure\Provider\Telegram\TelegramChannelProvider;
use App\Module\Communications\Infrastructure\Provider\Telegram\TelegramApiException;
use App\Module\Communications\Infrastructure\Provider\Telegram\TelegramPermanentApiException;
use App\Module\Communications\Infrastructure\Provider\Telegram\TelegramTransientApiException;
use PHPUnit\Framework\TestCase;

final class TelegramChannelProviderTest extends TestCase
{
    public function testCapabilitiesMatchBotApi(): void
    {
        $capabilities = (new TelegramChannelProvider(new RecordingTelegramApiClient()))->capabilities();

        self::assertTrue($capabilities->supportsButtons);
        self::assertFalse($capabilities->supportsMessageEdit);
        self::assertFalse($capabilities->supportsDeliveredStatus);
        self::assertFalse($capabilities->supportsReadStatus);
        self::assertTrue($capabilities->supportsDeepLink);
    }

    public function testSendsTextAndInlineButtons(): void
    {
        $client = new RecordingTelegramApiClient();
        $result = (new TelegramChannelProvider($client, '123:token'))->send(new OutboundMessageRequest(
            CommunicationProvider::TELEGRAM,
            '123456',
            'Подтвердите занятие',
            [[['text' => 'Будем', 'payload' => 'confirm'], ['text' => 'Открыть', 'url' => 'https://example.test']]],
        ));

        self::assertSame('321', $result->providerMessageId);
        self::assertSame('123:token', $client->token);
        self::assertSame('123456', $client->payload['chat_id']);
        self::assertSame('confirm', $client->payload['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        self::assertSame('https://example.test', $client->payload['reply_markup']['inline_keyboard'][0][1]['url']);
    }

    public function testAuthenticatesWebhookSecret(): void
    {
        $provider = new TelegramChannelProvider(new RecordingTelegramApiClient());

        self::assertTrue($provider->authenticateWebhook(new WebhookAuthenticationRequest(
            '{}',
            ['X-Telegram-Bot-Api-Secret-Token' => ['secret-token']],
            hash('sha256', 'secret-token'),
        )));
        self::assertFalse($provider->authenticateWebhook(new WebhookAuthenticationRequest('{}', [], hash('sha256', 'secret-token'))));
    }

    public function testNormalizesCallbackActivationAndUnsupportedText(): void
    {
        $provider = new TelegramChannelProvider(new RecordingTelegramApiClient());
        $button = $provider->parseWebhook(['update_id' => 10, 'callback_query' => ['data' => 'confirm', 'from' => ['id' => 42]]])[0];
        $activation = $provider->parseWebhook(['update_id' => 11, 'message' => ['text' => '/start token_123', 'from' => ['id' => 43]]])[0];
        $text = $provider->parseWebhook(['update_id' => 12, 'message' => ['text' => 'Будем', 'from' => ['id' => 44]]])[0];

        self::assertSame('BUTTON', $button->payload['kind']);
        self::assertSame('42', $button->payload['userId']);
        self::assertSame('ACTIVATION', $activation->payload['kind']);
        self::assertSame('token_123', $activation->payload['activationToken']);
        self::assertSame('TEXT_UNSUPPORTED', $text->payload['kind']);
        self::assertNull($text->payload['action']);
        self::assertSame(['10'], $provider->extractWebhookEventIds(['update_id' => 10]));
    }

    public function testMapsPermanentTransientAndMalformedSuccessResponses(): void
    {
        foreach ([
            [new TelegramApiResponse(400, ['ok' => false, 'error_code' => 400]), TelegramPermanentApiException::class],
            [new TelegramApiResponse(429, ['ok' => false, 'error_code' => 429]), TelegramTransientApiException::class],
            [new TelegramApiResponse(200, ['ok' => true, 'result' => []]), TelegramApiException::class],
        ] as [$response, $exceptionClass]) {
            try {
                (new TelegramChannelProvider(new RecordingTelegramApiClient($response), 'token'))->send(new OutboundMessageRequest(CommunicationProvider::TELEGRAM, '123', 'Text'));
                self::fail('Provider error expected.');
            } catch (\Throwable $exception) {
                self::assertInstanceOf($exceptionClass, $exception);
            }
        }
    }
}

final class RecordingTelegramApiClient implements TelegramApiClient
{
    public string $token = '';
    /** @var array<string, mixed> */
    public array $payload = [];

    public function __construct(private readonly TelegramApiResponse $response = new TelegramApiResponse(200, ['ok' => true, 'result' => ['message_id' => 321]]))
    {
    }

    public function sendMessage(string $botToken, array $payload): TelegramApiResponse
    {
        $this->token = $botToken;
        $this->payload = $payload;

        return $this->response;
    }
}
