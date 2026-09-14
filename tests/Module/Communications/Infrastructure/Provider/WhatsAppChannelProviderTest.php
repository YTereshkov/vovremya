<?php

declare(strict_types=1);

namespace App\Tests\Module\Communications\Infrastructure\Provider\WhatsApp;

use App\Module\Communications\Application\OutboundMessageRequest;
use App\Module\Communications\Application\WebhookAuthenticationRequest;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Communications\Infrastructure\Provider\WhatsApp\WhatsAppApiClient;
use App\Module\Communications\Infrastructure\Provider\WhatsApp\WhatsAppApiResponse;
use App\Module\Communications\Infrastructure\Provider\WhatsApp\WhatsAppChannelProvider;
use App\Module\Communications\Infrastructure\Provider\WhatsApp\WhatsAppApiException;
use App\Module\Communications\Infrastructure\Provider\WhatsApp\WhatsAppPermanentApiException;
use App\Module\Communications\Infrastructure\Provider\WhatsApp\WhatsAppTransientApiException;
use PHPUnit\Framework\TestCase;

final class WhatsAppChannelProviderTest extends TestCase
{
    public function testCapabilitiesIncludeDocumentedDeliveryStatuses(): void
    {
        $capabilities = (new WhatsAppChannelProvider(new RecordingWhatsAppApiClient()))->capabilities();

        self::assertTrue($capabilities->supportsButtons);
        self::assertFalse($capabilities->supportsMessageEdit);
        self::assertTrue($capabilities->supportsDeliveredStatus);
        self::assertTrue($capabilities->supportsReadStatus);
        self::assertTrue($capabilities->supportsDeepLink);
    }

    public function testSendsInteractiveReplyButtons(): void
    {
        $client = new RecordingWhatsAppApiClient();
        $result = (new WhatsAppChannelProvider($client, 'access-token', 'phone-id', 'app-secret'))->send(new OutboundMessageRequest(
            CommunicationProvider::WHATSAPP,
            '79991234567',
            'Подтвердите занятие',
            [[['text' => 'Будем', 'payload' => 'confirm'], ['text' => 'Не сможем', 'payload' => 'cancel']]],
        ));

        self::assertSame('wamid.1', $result->providerMessageId);
        self::assertSame('access-token', $client->token);
        self::assertSame('phone-id', $client->phoneNumberId);
        self::assertSame('interactive', $client->payload['type']);
        self::assertSame('confirm', $client->payload['interactive']['action']['buttons'][0]['reply']['id']);
    }

    public function testAuthenticatesSignedRawBody(): void
    {
        $provider = new WhatsAppChannelProvider(new RecordingWhatsAppApiClient(), appSecret: 'app-secret');
        $body = '{"entry":[]}';
        $signature = 'sha256='.hash_hmac('sha256', $body, 'app-secret');

        self::assertTrue($provider->authenticateWebhook(new WebhookAuthenticationRequest(
            $body,
            ['X-Hub-Signature-256' => [$signature]],
            hash('sha256', 'verify-token'),
        )));
        self::assertFalse($provider->authenticateWebhook(new WebhookAuthenticationRequest($body, ['X-Hub-Signature-256' => ['sha256=wrong']], hash('sha256', 'verify-token'))));
    }

    public function testNormalizesButtonActivationDeliveryAndIgnoresUnknownStatus(): void
    {
        $provider = new WhatsAppChannelProvider(new RecordingWhatsAppApiClient());
        $events = $provider->parseWebhook($this->payload([
            ['from' => '79990000001', 'id' => 'wamid.button', 'type' => 'interactive', 'interactive' => ['button_reply' => ['id' => 'confirm', 'title' => 'Будем']]],
            ['from' => '79990000002', 'id' => 'wamid.activation', 'type' => 'text', 'text' => ['body' => 'VOVREMYA_CONNECT token_123']],
            ['from' => '79990000003', 'id' => 'wamid.text', 'type' => 'text', 'text' => ['body' => 'Будем']],
        ], [
            ['id' => 'wamid.out', 'status' => 'delivered', 'timestamp' => '1789344000'],
            ['id' => 'wamid.out', 'status' => 'read', 'timestamp' => '1789344060'],
            ['id' => 'wamid.out', 'status' => 'sent', 'timestamp' => '1789343900'],
        ]));

        self::assertCount(5, $events);
        self::assertSame('BUTTON', $events[0]->payload['kind']);
        self::assertSame('confirm', $events[0]->payload['action']);
        self::assertSame('ACTIVATION', $events[1]->payload['kind']);
        self::assertSame('token_123', $events[1]->payload['activationToken']);
        self::assertSame('TEXT_UNSUPPORTED', $events[2]->payload['kind']);
        self::assertSame('DELIVERED', $events[3]->payload['status']);
        self::assertSame('READ', $events[4]->payload['status']);
        self::assertSame(['wamid.button', 'wamid.activation', 'wamid.text', 'wamid.out:delivered', 'wamid.out:read'], $provider->extractWebhookEventIds($this->payload(
            [
                ['from' => '1', 'id' => 'wamid.button', 'interactive' => ['button_reply' => ['id' => 'a']]],
                ['from' => '2', 'id' => 'wamid.activation', 'text' => ['body' => 'VOVREMYA_CONNECT token_123']],
                ['from' => '3', 'id' => 'wamid.text', 'text' => ['body' => 'text']],
            ],
            [['id' => 'wamid.out', 'status' => 'delivered'], ['id' => 'wamid.out', 'status' => 'read']],
        )));
    }

    public function testMapsPermanentTransientAndMalformedSuccessResponses(): void
    {
        foreach ([
            [new WhatsAppApiResponse(400, ['error' => ['code' => 100]]), WhatsAppPermanentApiException::class],
            [new WhatsAppApiResponse(503, ['error' => ['code' => 2]]), WhatsAppTransientApiException::class],
            [new WhatsAppApiResponse(200, ['messages' => []]), WhatsAppApiException::class],
        ] as [$response, $exceptionClass]) {
            try {
                (new WhatsAppChannelProvider(new RecordingWhatsAppApiClient($response), 'token', 'phone-id', 'secret'))->send(new OutboundMessageRequest(CommunicationProvider::WHATSAPP, '79990000000', 'Text'));
                self::fail('Provider error expected.');
            } catch (\Throwable $exception) {
                self::assertInstanceOf($exceptionClass, $exception);
            }
        }
    }

    /** @param list<array<string, mixed>> $messages
     *  @param list<array<string, mixed>> $statuses
     *  @return array<string, mixed>
     */
    private function payload(array $messages, array $statuses): array
    {
        return ['entry' => [['changes' => [['field' => 'messages', 'value' => ['messages' => $messages, 'statuses' => $statuses]]]]]];
    }
}

final class RecordingWhatsAppApiClient implements WhatsAppApiClient
{
    public string $token = '';
    public string $phoneNumberId = '';
    /** @var array<string, mixed> */
    public array $payload = [];

    public function __construct(private readonly WhatsAppApiResponse $response = new WhatsAppApiResponse(200, ['messages' => [['id' => 'wamid.1']]]))
    {
    }

    public function sendMessage(string $accessToken, string $phoneNumberId, array $payload): WhatsAppApiResponse
    {
        $this->token = $accessToken;
        $this->phoneNumberId = $phoneNumberId;
        $this->payload = $payload;

        return $this->response;
    }
}
