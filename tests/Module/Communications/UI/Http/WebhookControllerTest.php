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
use App\Tests\Module\Communications\Support\FakeChannelProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class WebhookControllerTest extends WebTestCase
{
    private \Symfony\Component\BrowserKit\AbstractBrowser $client;
    private AdministratorAccount $administrator;
    private \Doctrine\ORM\EntityManagerInterface $entityManager;
    private FakeChannelProvider $provider;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->administrator = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Webhook test', 'webhook-'.bin2hex(random_bytes(4)).'@example.test', 'test-password', 'Europe/Moscow');
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
        $url = '/webhooks/communications/max/'.$this->administrator->organizationId()->toRfc4122();
        $body = '{"type":"message"}';
        $headers = $this->headers('event-1', $body);

        $this->client->request('POST', $url, [], [], $headers, $body);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        self::assertSame(['accepted' => true, 'duplicate' => false], $this->json());

        $this->client->request('POST', $url, [], [], $headers, $body);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        self::assertSame(['accepted' => true, 'duplicate' => true], $this->json());
    }

    public function testWebhookRequiresProviderAuthenticationAndLimitsPayloadSize(): void
    {
        $url = '/webhooks/communications/max/'.$this->administrator->organizationId()->toRfc4122();
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
            $bus,
            self::getContainer()->get('limiter.communication_webhooks'),
        );
        $body = '{"id":"recoverable-event","type":"message"}';
        $request = Request::create(
            '/webhooks/communications/max/'.$this->administrator->organizationId()->toRfc4122(),
            'POST',
            server: $this->headers('recoverable-event', $body),
            content: $body,
        );

        $response = $controller->receive('max', $this->administrator->organizationId()->toRfc4122(), $request);
        self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $transport->reset();
        $publisher = new PublishPendingOutboundMessagesHandler(
            self::getContainer()->get(OrganizationDirectory::class),
            self::getContainer()->get(OrganizationContext::class),
            self::getContainer()->get(CommunicationStore::class),
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

        $handler = new ProcessWebhookInboxHandler(self::getContainer()->get(CommunicationStore::class), $registry);
        $context = self::getContainer()->get(OrganizationContext::class);
        $context->runWith($this->administrator->organizationId(), fn () => $handler($command));
        $context->runWith($this->administrator->organizationId(), fn () => $handler($command));
        self::assertSame(1, $this->provider->parsedWebhooks);
    }

    public function testWebhookProcessingRetriesTransientFailure(): void
    {
        $url = '/webhooks/communications/max/'.$this->administrator->organizationId()->toRfc4122();
        $body = '{"id":"retry-event","type":"message"}';
        $this->client->request('POST', $url, [], [], $this->headers('retry-event', $body), $body);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);

        $context = self::getContainer()->get(OrganizationContext::class);
        $store = self::getContainer()->get(CommunicationStore::class);
        $inboxes = $context->runWith($this->administrator->organizationId(), fn () => $store->pendingWebhooks());
        self::assertCount(1, $inboxes);
        $command = new ProcessWebhookInbox($this->administrator->organizationId(), $inboxes[0]->id());
        $handler = new ProcessWebhookInboxHandler($store, new ChannelProviderRegistry([$this->provider]));
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
        $base = '/webhooks/communications/max/'.$this->administrator->organizationId()->toRfc4122();
        $this->client->request('POST', str_replace('/max/', '/unknown/', $base), [], [], ['HTTP_X_WEBHOOK_EVENT_ID' => 'x'], '{}');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->client->request('POST', $base, [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->client->request('POST', '/webhooks/communications/max/01ARZ3NDEKTSV4RRFFQ69G5FAV', [], [], ['HTTP_X_WEBHOOK_EVENT_ID' => 'x'], '{}');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
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
