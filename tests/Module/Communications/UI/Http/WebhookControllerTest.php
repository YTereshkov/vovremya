<?php

declare(strict_types=1);

namespace App\Tests\Module\Communications\UI\Http;

use App\Module\Communications\Application\ChannelProviderRegistry;
use App\Module\Communications\Application\CommunicationStore;
use App\Module\Communications\Application\Message\ProcessWebhookInbox;
use App\Module\Communications\Application\Message\ProcessWebhookInboxHandler;
use App\Module\Communications\Application\Message\PublishPendingOutboundMessages;
use App\Module\Communications\Application\Message\PublishPendingOutboundMessagesHandler;
use App\Module\Communications\Application\WebhookInboxRecorder;
use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Application\OrganizationContext;
use App\Module\Organization\Application\OrganizationDirectory;
use App\Module\Organization\Application\OrganizationExistenceChecker;
use App\Module\Communications\UI\Http\WebhookController;
use App\Module\Clients\Domain\Model\ChannelConnection;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Communications\Infrastructure\Provider\Max\MaxApiClient;
use App\Module\Communications\Infrastructure\Provider\Max\MaxChannelProvider;
use App\Tests\Module\Communications\Support\FakeChannelProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Ulid;

final class WebhookControllerTest extends WebTestCase
{
    private \Symfony\Component\BrowserKit\AbstractBrowser $client;
    private AdministratorAccount $administrator;
    private \Doctrine\ORM\EntityManagerInterface $entityManager;
    private FakeChannelProvider $provider;
    private string $webhookRoutingKey;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->administrator = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Webhook test', 'webhook-'.bin2hex(random_bytes(4)).'@example.test', 'test-password', 'Europe/Moscow');
        $client = Client::create($this->administrator->organization(), 'Webhook recipient', 'ADULT', null, null);
        $this->entityManager->persist($client);
        $this->entityManager->flush();
        $channel = ChannelConnection::create($client, null, 'MAX', '123456789');
        $channel->activate();
        $channel->configureWebhookSecret('test-secret');
        $this->entityManager->persist($channel);
        $this->entityManager->flush();
        $this->webhookRoutingKey = $channel->webhookRoutingKey();
        $this->provider = new FakeChannelProvider();
        self::getContainer()->set(ChannelProviderRegistry::class, new ChannelProviderRegistry([$this->provider]));
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }
        $this->entityManager->clear();
        parent::tearDown();
    }

    public function testWebhookIsAcknowledgedAndDuplicateIsIdempotent(): void
    {
        $url = '/webhooks/communications/max/'.$this->webhookRoutingKey;
        $body = '{"type":"message"}';
        $headers = $this->headers('event-1', $body);

        $this->client->request('POST', $url, [], [], $headers, $body);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['accepted' => true, 'duplicate' => false], $this->json());

        $this->client->request('POST', $url, [], [], $headers, $body);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['accepted' => true, 'duplicate' => true], $this->json());
    }

    public function testWebhookRequiresProviderAuthenticationAndLimitsPayloadSize(): void
    {
        $url = '/webhooks/communications/max/'.$this->webhookRoutingKey;
        $body = '{"type":"message"}';
        $this->client->request('POST', $url, [], [], [
            'HTTP_X_WEBHOOK_EVENT_ID' => 'unauthenticated-event',
            'HTTP_X_WEBHOOK_SIGNATURE' => 'invalid',
            'CONTENT_TYPE' => 'application/json',
        ], $body);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $largeBody = '{"payload":"'.str_repeat('x', 262_144).'"}';
        $this->client->request('POST', $url, [], [], $this->headers('large-event', $largeBody), $largeBody);
        self::assertResponseStatusCodeSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
    }

    public function testStoredWebhookIsRepublishedAfterImmediateDispatchFailureAndClaimedOnce(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willThrowException(new TransportException('Redis unavailable.'));
        $registry = new ChannelProviderRegistry([$this->provider]);
        $controller = new WebhookController(
            self::getContainer()->get(WebhookInboxRecorder::class),
            $registry,
            self::getContainer()->get(OrganizationContext::class),
            self::getContainer()->get(OrganizationExistenceChecker::class),
            self::getContainer()->get(\App\Module\Clients\Application\ChannelConnectionResolver::class),
            $bus,
            self::getContainer()->get('limiter.communication_webhooks'),
        );
        $body = '{"id":"recoverable-event","type":"message"}';
        $request = Request::create(
            '/webhooks/communications/max/'.$this->webhookRoutingKey,
            'POST',
            server: $this->headers('recoverable-event', $body),
            content: $body,
        );

        $response = $controller->receive('max', $this->webhookRoutingKey, $request);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $transport->reset();
        $publisher = new PublishPendingOutboundMessagesHandler(
            self::getContainer()->get(OrganizationDirectory::class),
            self::getContainer()->get(OrganizationContext::class),
            self::getContainer()->get(CommunicationStore::class),
            self::getContainer()->get(\App\Module\Communications\Application\NormalizedWebhookEventStore::class),
            self::getContainer()->get(MessageBusInterface::class),
        );
        $publisher(new PublishPendingOutboundMessages());
        $publisher(new PublishPendingOutboundMessages());

        $commands = array_values(array_filter(
            $transport->getSent(),
            static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof ProcessWebhookInbox,
        ));
        self::assertGreaterThanOrEqual(2, count($commands));
        $command = $commands[array_key_last($commands)]->getMessage();
        self::assertInstanceOf(ProcessWebhookInbox::class, $command);

        $handler = new ProcessWebhookInboxHandler(
            self::getContainer()->get(CommunicationStore::class),
            $registry,
            self::getContainer()->get(\App\Module\Clients\Application\ChannelConnectionResolver::class),
            self::getContainer()->get(\App\Module\Communications\Application\NormalizedWebhookEventSink::class),
            self::getContainer()->get(MessageBusInterface::class),
        );
        $context = self::getContainer()->get(OrganizationContext::class);
        $context->runWith($this->administrator->organizationId(), fn () => $handler($command));
        $context->runWith($this->administrator->organizationId(), fn () => $handler($command));
        self::assertSame(1, $this->provider->parsedWebhooks);
    }

    public function testWebhookProcessingRetriesTransientFailure(): void
    {
        $url = '/webhooks/communications/max/'.$this->webhookRoutingKey;
        $body = '{"id":"retry-event","type":"message"}';
        $this->client->request('POST', $url, [], [], $this->headers('retry-event', $body), $body);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $context = self::getContainer()->get(OrganizationContext::class);
        $store = self::getContainer()->get(CommunicationStore::class);
        $inboxes = $context->runWith($this->administrator->organizationId(), fn () => $store->pendingWebhooks());
        self::assertCount(1, $inboxes);
        $command = new ProcessWebhookInbox($this->administrator->organizationId(), $inboxes[0]->id());
        $handler = new ProcessWebhookInboxHandler(
            $store,
            new ChannelProviderRegistry([$this->provider]),
            self::getContainer()->get(\App\Module\Clients\Application\ChannelConnectionResolver::class),
            self::getContainer()->get(\App\Module\Communications\Application\NormalizedWebhookEventSink::class),
            self::getContainer()->get(MessageBusInterface::class),
        );
        $this->provider->webhookFailure = new \RuntimeException('Temporary webhook failure.');

        try {
            $context->runWith($this->administrator->organizationId(), fn () => $handler($command));
            self::fail('The transient webhook failure must be retried by Messenger.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Temporary webhook failure.', $exception->getMessage());
        }

        $afterFailure = $context->runWith($this->administrator->organizationId(), fn () => $store->findWebhookById($inboxes[0]->id()));
        self::assertSame('RECEIVED', $afterFailure?->status()->value);
        self::assertSame(1, $afterFailure?->attempts());

        $this->provider->webhookFailure = null;
        $context->runWith($this->administrator->organizationId(), fn () => $handler($command));
        $processed = $context->runWith($this->administrator->organizationId(), fn () => $store->findWebhookById($inboxes[0]->id()));
        self::assertSame('PROCESSED', $processed?->status()->value);
        self::assertSame(2, $processed?->attempts());
    }

    public function testWebhookValidatesProviderTenantAndEventId(): void
    {
        $base = '/webhooks/communications/max/'.$this->webhookRoutingKey;
        $this->client->request('POST', str_replace('/max/', '/unknown/', $base), [], [], ['HTTP_X_WEBHOOK_EVENT_ID' => 'x'], '{}');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $body = '{"type":"message"}';
        $this->client->request('POST', $base, [], [], [
            'HTTP_X_WEBHOOK_SIGNATURE' => FakeChannelProvider::signature($body),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $this->client->request('POST', '/webhooks/communications/max/01ARZ3NDEKTSV4RRFFQ69G5FAV', [], [], ['HTTP_X_WEBHOOK_EVENT_ID' => 'x'], '{}');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testMaxWebhookCannotUseAnotherConnectionsRoutingKey(): void
    {
        $registry = new ChannelProviderRegistry([new MaxChannelProvider($this->createMock(MaxApiClient::class))]);
        $controller = new WebhookController(
            self::getContainer()->get(WebhookInboxRecorder::class),
            $registry,
            self::getContainer()->get(OrganizationContext::class),
            self::getContainer()->get(OrganizationExistenceChecker::class),
            self::getContainer()->get(\App\Module\Clients\Application\ChannelConnectionResolver::class),
            self::getContainer()->get(MessageBusInterface::class),
            self::getContainer()->get('limiter.communication_webhooks'),
        );
        $other = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Other webhook tenant', 'other-webhook-'.bin2hex(random_bytes(4)).'@example.test', 'test-password', 'UTC');
        $otherClient = Client::create($other->organization(), 'Other recipient', 'ADULT', null, null);
        $this->entityManager->persist($otherClient);
        $this->entityManager->flush();
        $otherChannel = ChannelConnection::create($otherClient, null, 'MAX', '987654321');
        $otherChannel->activate();
        $otherChannel->configureWebhookSecret('other-secret');
        $this->entityManager->persist($otherChannel);
        $this->entityManager->flush();

        $body = '{"updates":[{"update_type":"message_callback","callback":{"callback_id":"callback-cross-tenant","payload":"confirm"}}]}';
        $response = $controller->receive('max', $otherChannel->webhookRoutingKey(), Request::create('/', 'POST', server: [
            'HTTP_X_MAX_BOT_API_SECRET' => 'test-secret',
            'CONTENT_TYPE' => 'application/json',
        ], content: $body));
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM communication_webhook_inbox WHERE organization_id = ?',
            [$other->organizationId()->toRfc4122()],
        ));

        $response = $controller->receive('max', $this->webhookRoutingKey, Request::create('/', 'POST', server: [
            'HTTP_X_MAX_BOT_API_SECRET' => 'test-secret',
            'CONTENT_TYPE' => 'application/json',
        ], content: $body));
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testMaxInboxUsesStableProviderEventIdAcrossDifferentEnvelopes(): void
    {
        $registry = new ChannelProviderRegistry([new MaxChannelProvider($this->createMock(MaxApiClient::class))]);
        $controller = new WebhookController(
            self::getContainer()->get(WebhookInboxRecorder::class),
            $registry,
            self::getContainer()->get(OrganizationContext::class),
            self::getContainer()->get(OrganizationExistenceChecker::class),
            self::getContainer()->get(\App\Module\Clients\Application\ChannelConnectionResolver::class),
            self::getContainer()->get(MessageBusInterface::class),
            self::getContainer()->get('limiter.communication_webhooks'),
        );
        $headers = [
            'HTTP_X_MAX_BOT_API_SECRET' => 'test-secret',
            'HTTP_X_WEBHOOK_EVENT_ID' => 'caller-controlled-1',
            'CONTENT_TYPE' => 'application/json',
        ];
        $firstBody = '{"updates":[{"update_type":"message_callback","update_id":"u-1","callback":{"callback_id":"cb-stable","payload":"confirm"}}]}';
        $secondBody = '{"meta":{"batch":2},"updates":[{"update_type":"message_callback","update_id":"u-1","callback":{"callback_id":"cb-stable","payload":"confirm"}}]}';

        $first = $controller->receive('max', $this->webhookRoutingKey, Request::create('/', 'POST', server: $headers, content: $firstBody));
        $secondHeaders = $headers;
        $secondHeaders['HTTP_X_WEBHOOK_EVENT_ID'] = 'caller-controlled-2';
        $second = $controller->receive('max', $this->webhookRoutingKey, Request::create('/', 'POST', server: $secondHeaders, content: $secondBody));

        self::assertSame(Response::HTTP_OK, $first->getStatusCode());
        self::assertSame(Response::HTTP_OK, $second->getStatusCode());
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM communication_webhook_inbox WHERE organization_id = ? AND provider = ? AND external_event_id = ?',
            [$this->administrator->organizationId()->toRfc4122(), 'MAX', 'cb-stable'],
        ));
    }

    public function testNormalizedMaxEventsAreDurablyDeduplicatedAcrossBatches(): void
    {
        $registry = new ChannelProviderRegistry([new MaxChannelProvider($this->createMock(MaxApiClient::class))]);
        $controller = new WebhookController(
            self::getContainer()->get(WebhookInboxRecorder::class),
            $registry,
            self::getContainer()->get(OrganizationContext::class),
            self::getContainer()->get(OrganizationExistenceChecker::class),
            self::getContainer()->get(\App\Module\Clients\Application\ChannelConnectionResolver::class),
            self::getContainer()->get(MessageBusInterface::class),
            self::getContainer()->get('limiter.communication_webhooks'),
        );
        $headers = ['HTTP_X_MAX_BOT_API_SECRET' => 'test-secret', 'CONTENT_TYPE' => 'application/json'];
        $firstBody = '{"updates":[{"update_type":"message_callback","callback":{"callback_id":"cb-repeat","payload":"confirm"},"user":{"user_id":123456789}}]}';
        $secondBody = '{"meta":{"batch":2},"updates":[{"update_type":"message_callback","callback":{"callback_id":"cb-repeat","payload":"confirm"},"user":{"user_id":123456789}},{"update_type":"message_callback","callback":{"callback_id":"cb-next","payload":"cancel"},"user":{"user_id":123456789}}]}';
        $controller->receive('max', $this->webhookRoutingKey, Request::create('/', 'POST', server: $headers, content: $firstBody));
        $controller->receive('max', $this->webhookRoutingKey, Request::create('/', 'POST', server: $headers, content: $secondBody));

        $context = self::getContainer()->get(OrganizationContext::class);
        $store = self::getContainer()->get(CommunicationStore::class);
        $handler = new ProcessWebhookInboxHandler(
            $store,
            $registry,
            self::getContainer()->get(\App\Module\Clients\Application\ChannelConnectionResolver::class),
            self::getContainer()->get(\App\Module\Communications\Application\NormalizedWebhookEventSink::class),
            self::getContainer()->get(MessageBusInterface::class),
        );
        $inboxes = $context->runWith($this->administrator->organizationId(), fn () => $store->pendingWebhooks());
        self::assertCount(2, $inboxes);
        foreach ($inboxes as $inbox) {
            $context->runWith($this->administrator->organizationId(), fn () => $handler(new ProcessWebhookInbox($this->administrator->organizationId(), $inbox->id())));
        }

        $consumer = new class implements \App\Module\Communications\Application\NormalizedWebhookEventConsumer {
            /** @var list<string> */
            public array $eventIds = [];

            public function consume(\App\Module\Communications\Domain\Model\NormalizedWebhookEvent $event): void
            {
                $this->eventIds[] = $event->externalEventId();
            }
        };
        $normalizedStore = self::getContainer()->get(\App\Module\Communications\Application\NormalizedWebhookEventStore::class);
        $normalizedHandler = new \App\Module\Communications\Application\Message\ProcessNormalizedWebhookEventHandler($normalizedStore, [$consumer]);
        foreach ($context->runWith($this->administrator->organizationId(), fn () => $normalizedStore->pending()) as $event) {
            $context->runWith($this->administrator->organizationId(), fn () => $normalizedHandler(new \App\Module\Communications\Application\Message\ProcessNormalizedWebhookEvent($this->administrator->organizationId(), $event->id())));
        }
        sort($consumer->eventIds);
        self::assertSame(['cb-next', 'cb-repeat'], $consumer->eventIds);

        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM communication_normalized_events WHERE organization_id = ? AND provider = ? AND external_event_id = ?',
            [$this->administrator->organizationId()->toRfc4122(), 'MAX', 'cb-repeat'],
        ));
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM communication_normalized_events WHERE organization_id = ? AND provider = ? AND external_event_id = ?',
            [$this->administrator->organizationId()->toRfc4122(), 'MAX', 'cb-next'],
        ));
    }

    public function testWorkerRechecksTenantConnectionBeforeParsingWebhook(): void
    {
        $url = '/webhooks/communications/max/'.$this->webhookRoutingKey;
        $body = '{"type":"message","id":"inactive-connection"}';
        $this->client->request('POST', $url, [], [], $this->headers('inactive-connection', $body), $body);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $channel = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT id FROM channel_connections WHERE webhook_routing_key = ?',
            [$this->webhookRoutingKey],
        );
        self::assertIsArray($channel);
        $channelEntity = $this->entityManager->getReference(ChannelConnection::class, Ulid::fromString((string) $channel['id']));
        $channelEntity->deactivate();
        $this->entityManager->flush();

        $store = self::getContainer()->get(CommunicationStore::class);
        $inboxes = self::getContainer()->get(OrganizationContext::class)->runWith($this->administrator->organizationId(), fn () => $store->pendingWebhooks());
        $handler = new ProcessWebhookInboxHandler(
            $store,
            new ChannelProviderRegistry([$this->provider]),
            self::getContainer()->get(\App\Module\Clients\Application\ChannelConnectionResolver::class),
            self::getContainer()->get(\App\Module\Communications\Application\NormalizedWebhookEventSink::class),
            self::getContainer()->get(MessageBusInterface::class),
        );
        $context = self::getContainer()->get(OrganizationContext::class);
        $context->runWith($this->administrator->organizationId(), fn () => $handler(new ProcessWebhookInbox($this->administrator->organizationId(), $inboxes[0]->id())));
        $failed = $context->runWith($this->administrator->organizationId(), fn () => $store->findWebhookById($inboxes[0]->id()));
        self::assertSame('FAILED', $failed?->status()->value);
    }

    public function testPendingMaxConnectionIsActivatedOnlyByMatchingBotStartedUser(): void
    {
        $client = Client::create($this->administrator->organization(), 'Pending recipient', 'ADULT', null, null);
        $this->entityManager->persist($client);
        $this->entityManager->flush();
        $channel = ChannelConnection::create($client, null, 'MAX', '777');
        $channel->configureWebhookSecret('pending-secret');
        $this->entityManager->persist($channel);
        $this->entityManager->flush();

        $provider = new MaxChannelProvider($this->createStub(MaxApiClient::class));
        $registry = new ChannelProviderRegistry([$provider]);
        $controller = new WebhookController(
            self::getContainer()->get(WebhookInboxRecorder::class),
            $registry,
            self::getContainer()->get(OrganizationContext::class),
            self::getContainer()->get(OrganizationExistenceChecker::class),
            self::getContainer()->get(\App\Module\Clients\Application\ChannelConnectionResolver::class),
            self::getContainer()->get(MessageBusInterface::class),
            self::getContainer()->get('limiter.communication_webhooks'),
        );
        $body = '{"update_type":"bot_started","update_id":"activation-1","user":{"user_id":777}}';
        $response = $controller->receive('max', $channel->webhookRoutingKey(), Request::create('/', 'POST', server: [
            'HTTP_X_MAX_BOT_API_SECRET' => 'pending-secret',
            'CONTENT_TYPE' => 'application/json',
        ], content: $body));
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $store = self::getContainer()->get(CommunicationStore::class);
        $context = self::getContainer()->get(OrganizationContext::class);
        $inbox = $context->runWith($this->administrator->organizationId(), fn () => $store->pendingWebhooks()[0]);
        $handler = new ProcessWebhookInboxHandler(
            $store,
            $registry,
            self::getContainer()->get(\App\Module\Clients\Application\ChannelConnectionResolver::class),
            self::getContainer()->get(\App\Module\Communications\Application\NormalizedWebhookEventSink::class),
            self::getContainer()->get(MessageBusInterface::class),
        );
        $context->runWith($this->administrator->organizationId(), fn () => $handler(new ProcessWebhookInbox($this->administrator->organizationId(), $inbox->id())));
        self::assertTrue($channel->isActive());
    }

    public function testDisabledMaxConnectionCannotBeReactivatedByQueuedWebhookOrProduceButtonEvent(): void
    {
        $provider = new MaxChannelProvider($this->createStub(MaxApiClient::class));
        $registry = new ChannelProviderRegistry([$provider]);
        $controller = new WebhookController(
            self::getContainer()->get(WebhookInboxRecorder::class),
            $registry,
            self::getContainer()->get(OrganizationContext::class),
            self::getContainer()->get(OrganizationExistenceChecker::class),
            self::getContainer()->get(\App\Module\Clients\Application\ChannelConnectionResolver::class),
            self::getContainer()->get(MessageBusInterface::class),
            self::getContainer()->get('limiter.communication_webhooks'),
        );
        $headers = ['HTTP_X_MAX_BOT_API_SECRET' => 'test-secret', 'CONTENT_TYPE' => 'application/json'];
        $bodies = [
            '{"updates":[{"update_type":"bot_started","update_id":"old-activation","user":{"user_id":123456789}},{"update_type":"message_callback","callback":{"callback_id":"old-button","payload":"confirm"},"user":{"user_id":123456789}}]}',
            '{"update_type":"bot_started","update_id":"repeated-activation","user":{"user_id":123456789}}',
        ];
        foreach ($bodies as $body) {
            self::assertSame(Response::HTTP_OK, $controller->receive(
                'max',
                $this->webhookRoutingKey,
                Request::create('/', 'POST', server: $headers, content: $body),
            )->getStatusCode());
        }

        $resolver = self::getContainer()->get(\App\Module\Clients\Application\ChannelConnectionResolver::class);
        $channel = $resolver->findChannelByRoutingKey('MAX', $this->webhookRoutingKey);
        self::assertNotNull($channel);
        $channel->deactivate();
        $this->entityManager->flush();

        $context = self::getContainer()->get(OrganizationContext::class);
        $store = self::getContainer()->get(CommunicationStore::class);
        $handler = new ProcessWebhookInboxHandler(
            $store,
            $registry,
            $resolver,
            self::getContainer()->get(\App\Module\Communications\Application\NormalizedWebhookEventSink::class),
            self::getContainer()->get(MessageBusInterface::class),
        );
        foreach ($context->runWith($this->administrator->organizationId(), fn () => $store->pendingWebhooks()) as $inbox) {
            $context->runWith($this->administrator->organizationId(), fn () => $handler(new ProcessWebhookInbox($this->administrator->organizationId(), $inbox->id())));
        }

        self::assertFalse($channel->isActive());
        self::assertSame('DISABLED', $channel->state());
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM communication_normalized_events WHERE organization_id = ? AND external_event_id = ?',
            [$this->administrator->organizationId()->toRfc4122(), 'old-button'],
        ));
    }

    public function testMaxCallbacksFromWrongOrMissingUserAreNotActionable(): void
    {
        $registry = new ChannelProviderRegistry([new MaxChannelProvider($this->createMock(MaxApiClient::class))]);
        $controller = new WebhookController(
            self::getContainer()->get(WebhookInboxRecorder::class),
            $registry,
            self::getContainer()->get(OrganizationContext::class),
            self::getContainer()->get(OrganizationExistenceChecker::class),
            self::getContainer()->get(\App\Module\Clients\Application\ChannelConnectionResolver::class),
            self::getContainer()->get(MessageBusInterface::class),
            self::getContainer()->get('limiter.communication_webhooks'),
        );
        $headers = ['HTTP_X_MAX_BOT_API_SECRET' => 'test-secret', 'CONTENT_TYPE' => 'application/json'];
        foreach ([
            '{"update_type":"message_callback","callback":{"callback_id":"cb-wrong","payload":"confirm"},"user":{"user_id":999}}',
            '{"update_type":"message_callback","callback":{"callback_id":"cb-missing","payload":"confirm"}}',
        ] as $body) {
            $response = $controller->receive('max', $this->webhookRoutingKey, Request::create('/', 'POST', server: $headers, content: $body));
            self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        }

        $context = self::getContainer()->get(OrganizationContext::class);
        $store = self::getContainer()->get(CommunicationStore::class);
        $handler = new ProcessWebhookInboxHandler(
            $store,
            $registry,
            self::getContainer()->get(\App\Module\Clients\Application\ChannelConnectionResolver::class),
            self::getContainer()->get(\App\Module\Communications\Application\NormalizedWebhookEventSink::class),
            self::getContainer()->get(MessageBusInterface::class),
        );
        foreach ($context->runWith($this->administrator->organizationId(), fn () => $store->pendingWebhooks()) as $inbox) {
            $context->runWith($this->administrator->organizationId(), fn () => $handler(new ProcessWebhookInbox($this->administrator->organizationId(), $inbox->id())));
        }
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM communication_normalized_events WHERE organization_id = ? AND provider = ? AND external_event_id IN (?, ?)',
            [$this->administrator->organizationId()->toRfc4122(), 'MAX', 'cb-wrong', 'cb-missing'],
        ));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    private function headers(string $eventId, string $body): array
    {
        return [
            'HTTP_X_WEBHOOK_EVENT_ID' => $eventId,
            'HTTP_X_WEBHOOK_SIGNATURE' => FakeChannelProvider::signature($body),
            'CONTENT_TYPE' => 'application/json',
        ];
    }
}
