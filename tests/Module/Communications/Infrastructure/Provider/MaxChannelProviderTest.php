<?php

declare(strict_types=1);

namespace App\Tests\Module\Communications\Infrastructure\Provider\Max;

use App\Module\Communications\Application\OutboundMessageRequest;
use App\Module\Communications\Application\WebhookAuthenticationRequest;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Communications\Infrastructure\Provider\Max\MaxApiClient;
use App\Module\Communications\Infrastructure\Provider\Max\MaxPermanentApiException;
use App\Module\Communications\Infrastructure\Provider\Max\MaxApiResponse;
use App\Module\Communications\Infrastructure\Provider\Max\MaxChannelProvider;
use App\Module\Communications\Infrastructure\Provider\Max\MaxTransientApiException;
use PHPUnit\Framework\TestCase;

final class MaxChannelProviderTest extends TestCase
{
    public function testCapabilitiesDoNotInventDeliveryOrReadStatuses(): void
    {
        $provider = new MaxChannelProvider(new RecordingMaxApiClient());
        $capabilities = $provider->capabilities();

        self::assertTrue($capabilities->supportsButtons);
        self::assertFalse($capabilities->supportsMessageEdit);
        self::assertFalse($capabilities->supportsDeliveredStatus);
        self::assertFalse($capabilities->supportsReadStatus);
        self::assertTrue($capabilities->supportsDeepLink);
    }

    public function testSendsMessageWithRawAuthorizationAndInlineCallbackKeyboard(): void
    {
        $client = new RecordingMaxApiClient();
        $provider = new MaxChannelProvider($client, 'default-token');

        $result = $provider->send(new OutboundMessageRequest(
            CommunicationProvider::MAX,
            '123456789',
            'Подтвердите занятие',
            [[
                ['text' => 'Будем', 'payload' => 'confirm'],
                ['label' => 'Не сможем', 'action' => 'cancel'],
            ]],
            [],
            'outbox-1',
        ));

        self::assertSame('max-message-1', $result->providerMessageId);
        self::assertSame('default-token', $client->accessToken);
        self::assertSame('123456789', $client->recipientAddress);
        self::assertSame('outbox-1', $client->idempotencyKey);
        self::assertSame([
            'text' => 'Подтвердите занятие',
            'attachments' => [[
                'type' => 'inline_keyboard',
                'payload' => ['buttons' => [[
                    ['type' => 'callback', 'text' => 'Будем', 'payload' => 'confirm'],
                    ['type' => 'callback', 'text' => 'Не сможем', 'payload' => 'cancel'],
                ]]],
            ]],
        ], $client->payload);
    }

    public function testRendersDocumentedLinkButton(): void
    {
        $client = new RecordingMaxApiClient();
        (new MaxChannelProvider($client, 'token'))->send(new OutboundMessageRequest(
            CommunicationProvider::MAX,
            '123',
            'Откройте расписание',
            [[['type' => 'link', 'text' => 'Открыть', 'url' => 'https://example.test/schedule']]],
            idempotencyKey: 'outbox-link',
        ));

        self::assertSame('link', $client->payload['attachments'][0]['payload']['buttons'][0][0]['type']);
        self::assertSame('https://example.test/schedule', $client->payload['attachments'][0]['payload']['buttons'][0][0]['url']);
    }

    public function testRejectsPerMessageAccessTokenAndInvalidRecipient(): void
    {
        $client = new RecordingMaxApiClient();
        $provider = new MaxChannelProvider($client, 'default-token');

        try {
            $provider->send(new OutboundMessageRequest(
                CommunicationProvider::MAX,
                '42',
                'Текст',
                metadata: ['accessToken' => 'connection-token'],
                idempotencyKey: 'outbox-2',
            ));
            self::fail('Credentials must not be accepted through message metadata.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(\DomainException::class);
        $provider->send(new OutboundMessageRequest(CommunicationProvider::MAX, 'phone:+79990000000', 'Текст'));
    }

    public function testRejectsZeroUserIdentifiers(): void
    {
        $provider = new MaxChannelProvider(new RecordingMaxApiClient(), 'token');
        foreach (['0', '000'] as $address) {
            try {
                $provider->send(new OutboundMessageRequest(CommunicationProvider::MAX, $address, 'Текст'));
                self::fail('Zero MAX user id must be rejected.');
            } catch (\DomainException) {
                self::assertTrue(true);
            }
        }
    }

    public function testMapsProviderErrorsToPermanentAndTransientExceptions(): void
    {
        $permanent = new RecordingMaxApiClient(new MaxApiResponse(401, ['error' => 'unauthorized']));
        try {
            (new MaxChannelProvider($permanent, 'token'))->send(new OutboundMessageRequest(CommunicationProvider::MAX, '1', 'Текст'));
            self::fail('Expected permanent MAX error.');
        } catch (MaxPermanentApiException $exception) {
            self::assertSame(401, $exception->statusCode);
            self::assertFalse($exception->transient);
        }

        $transient = new RecordingMaxApiClient(new MaxApiResponse(503, null));
        try {
            (new MaxChannelProvider($transient, 'token'))->send(new OutboundMessageRequest(CommunicationProvider::MAX, '1', 'Текст'));
            self::fail('Expected transient MAX error.');
        } catch (MaxTransientApiException $exception) {
            self::assertSame(503, $exception->statusCode);
            self::assertTrue($exception->transient);
        }
    }

    public function testAuthenticatesSecretHeaderAndRejectsMissingConfiguration(): void
    {
        $provider = new MaxChannelProvider(new RecordingMaxApiClient());
        $request = new WebhookAuthenticationRequest('{}', ['X-Max-Bot-Api-Secret' => ['max-secret']], hash('sha256', 'max-secret'));
        self::assertTrue($provider->authenticateWebhook($request));
        self::assertFalse($provider->authenticateWebhook(new WebhookAuthenticationRequest('{}', ['x-max-bot-api-secret' => ['wrong']])));
        self::assertFalse((new MaxChannelProvider(new RecordingMaxApiClient()))->authenticateWebhook(new WebhookAuthenticationRequest('{}', ['X-Max-Bot-Api-Secret' => ['max-secret']])));
    }

    public function testButtonCallbackIsNormalizedAndTextIsNeverInterpreted(): void
    {
        $provider = new MaxChannelProvider(new RecordingMaxApiClient());
        $events = $provider->parseWebhook([
            'updates' => [
                [
                    'update_type' => 'message_callback',
                    'update_id' => 'update-1',
                    'callback' => ['callback_id' => 'callback-1', 'payload' => 'confirm'],
                    'user' => ['user_id' => 77],
                ],
                [
                    'update_type' => 'message_created',
                    'update_id' => 'update-2',
                    'message' => ['body' => ['text' => 'Будем']],
                    'user' => ['user_id' => 88],
                ],
            ],
        ]);

        self::assertCount(2, $events);
        self::assertSame('callback-1', $events[0]->externalEventId);
        self::assertSame('BUTTON', $events[0]->payload['kind']);
        self::assertSame('confirm', $events[0]->payload['action']);
        self::assertSame('TEXT_UNSUPPORTED', $events[1]->payload['kind']);
        self::assertNull($events[1]->payload['action']);
    }

    public function testExtractsCallbackUserFromDocumentedUserObject(): void
    {
        $provider = new MaxChannelProvider(new RecordingMaxApiClient());
        $events = $provider->parseWebhook([
            'update_type' => 'message_callback',
            'callback' => ['callback_id' => 'callback-user', 'payload' => 'confirm'],
            'user' => ['user_id' => 123],
        ]);

        self::assertSame('123', $events[0]->payload['userId']);
        self::assertSame(['callback-user'], $provider->extractWebhookEventIds([
            'updates' => [['update_type' => 'message_callback', 'callback' => ['callback_id' => 'callback-user']]],
        ]));
    }

    public function testBotStartedCarriesActivationToken(): void
    {
        $provider = new MaxChannelProvider(new RecordingMaxApiClient());
        $events = $provider->parseWebhook([
            'update_type' => 'bot_started',
            'update_id' => 'start-1',
            'user' => ['user_id' => 42],
            'payload' => 'connect-token',
        ]);

        self::assertCount(1, $events);
        self::assertSame('ACTIVATION', $events[0]->payload['kind']);
        self::assertSame('42', $events[0]->payload['userId']);
        self::assertSame('connect-token', $events[0]->payload['activationToken']);
    }
}

final class RecordingMaxApiClient implements MaxApiClient
{
    public string $accessToken = '';
    public string $recipientAddress = '';
    public array $payload = [];
    public string $idempotencyKey = '';

    public function __construct(private readonly MaxApiResponse $response = new MaxApiResponse(200, ['message' => ['id' => 'max-message-1']]))
    {
    }

    public function sendMessage(string $accessToken, string $recipientAddress, array $payload, string $idempotencyKey, ?string $chatId = null): MaxApiResponse
    {
        $this->accessToken = $accessToken;
        $this->recipientAddress = $recipientAddress;
        $this->payload = $payload;
        $this->idempotencyKey = $idempotencyKey;

        return $this->response;
    }
}
