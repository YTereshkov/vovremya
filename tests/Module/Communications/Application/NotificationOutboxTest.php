<?php

declare(strict_types=1);

namespace App\Tests\Module\Communications\Application;

use App\Module\Communications\Application\NotificationOutbox;
use App\Module\Communications\Application\CommunicationStore;
use App\Module\Communications\Application\ChannelProviderRegistry;
use App\Module\Communications\Application\ChannelCapabilities;
use App\Module\Communications\Application\DeliveryStatusConsumer;
use App\Module\Communications\Application\OutboundMessageRetryService;
use App\Module\Communications\Application\Message\SendOutboundMessage;
use App\Module\Communications\Application\Message\SendOutboundMessageHandler;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Communications\Domain\Model\NotificationIntent;
use App\Module\Communications\Domain\Model\OutboundMessage;
use App\Module\Communications\Domain\Model\NormalizedWebhookEvent;
use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Clients\Domain\Model\ChannelConnection;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Organization\Application\OrganizationContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use App\Tests\Module\Communications\Support\FakeChannelProvider;

final class NotificationOutboxTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private AdministratorAccount $administrator;
    private OrganizationContext $context;
    private CommunicationStore $store;
    private NotificationOutbox $outbox;
    private ChannelConnection $channel;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->administrator = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Outbox test', 'outbox-'.bin2hex(random_bytes(4)).'@example.test', 'test-password', 'Europe/Moscow');
        $client = Client::create($this->administrator->organization(), 'Outbox recipient', 'ADULT', null, null);
        $this->entityManager->persist($client);
        $this->entityManager->flush();
        $this->channel = ChannelConnection::create($client, null, 'MAX', '123456789');
        $this->channel->activate();
        $this->entityManager->persist($this->channel);
        $this->entityManager->flush();
        $this->context = self::getContainer()->get(OrganizationContext::class);
        $this->store = self::getContainer()->get(CommunicationStore::class);
        $this->outbox = new NotificationOutbox(
            $this->entityManager,
            $this->store,
            $this->context,
            self::getContainer()->get(\App\Module\Clients\Application\ChannelConnectionResolver::class),
        );
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }
        $this->entityManager->clear();
        parent::tearDown();
    }

    public function testIntentAndOutboxAreStoredTogether(): void
    {
        $message = $this->queue();

        self::assertSame(CommunicationProvider::MAX, $message->provider());
        self::assertSame('PENDING', $message->status()->value);

        $pending = $this->context->runWith($this->administrator->organizationId(), fn () => $this->store->pendingOutbound());
        self::assertCount(1, $pending);
        self::assertSame($message->id()->toRfc4122(), $pending[0]->id()->toRfc4122());
    }

    public function testClaimIsTenantScopedAndRecoversExpiredLease(): void
    {
        $message = $this->queue();
        $claimed = $this->context->runWith($this->administrator->organizationId(), fn () => $this->store->claimOutbound($message->id()));
        self::assertInstanceOf(OutboundMessage::class, $claimed);
        self::assertSame(1, $claimed->attempts());
        self::assertNull($this->context->runWith($this->administrator->organizationId(), fn () => $this->store->claimOutbound($message->id())));

        $other = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Other outbox tenant', 'other-outbox-'.bin2hex(random_bytes(4)).'@example.test', 'test-password', 'UTC');
        self::assertNull($this->context->runWith($other->organizationId(), fn () => $this->store->claimOutbound($message->id())));

        $this->entityManager->getConnection()->executeStatement(
            "UPDATE communication_outbox SET processing_started_at = NOW() - INTERVAL '10 minutes' WHERE id = ?",
            [$message->id()->toRfc4122()],
        );
        $recovered = $this->context->runWith($this->administrator->organizationId(), fn () => $this->store->claimOutbound($message->id()));
        self::assertInstanceOf(OutboundMessage::class, $recovered);
        self::assertSame(2, $recovered->attempts());
    }

    public function testCancelledIntentCannotBePublishedOrClaimed(): void
    {
        $message = $this->queue();
        $intent = $this->entityManager->find(NotificationIntent::class, $message->notificationIntentId());
        self::assertInstanceOf(NotificationIntent::class, $intent);
        $intent->cancel();
        $this->entityManager->flush();

        $pending = $this->context->runWith($this->administrator->organizationId(), fn () => $this->store->pendingOutbound());
        self::assertSame([], $pending);
        self::assertNull($this->context->runWith($this->administrator->organizationId(), fn () => $this->store->claimOutbound($message->id())));
    }

    public function testCredentialsCannotBePersistedInOutboxMetadata(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->context->runWith($this->administrator->organizationId(), fn () => $this->outbox->queue(
            'MAX_TEST',
            $this->channel->id(),
            CommunicationProvider::MAX,
            $this->channel->address(),
            'Текст',
            metadata: ['accessToken' => 'must-not-be-stored'],
        ));
    }

    public function testUnknownAndNestedMetadataCannotBePersisted(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->context->runWith($this->administrator->organizationId(), fn () => $this->outbox->queue(
            'MAX_TEST',
            $this->channel->id(),
            CommunicationProvider::MAX,
            $this->channel->address(),
            'Текст',
            metadata: ['max' => ['authorization' => 'secret']],
        ));
    }

    public function testInactiveChannelCannotBeQueued(): void
    {
        $this->channel->deactivate();
        $this->entityManager->flush();

        $this->expectException(\DomainException::class);
        $this->context->runWith($this->administrator->organizationId(), fn () => $this->outbox->queue(
            'MAX_TEST',
            $this->channel->id(),
            CommunicationProvider::MAX,
            $this->channel->address(),
            'Текст',
        ));
    }

    public function testSendHandlerRetriesAndUsesStableIdempotencyKey(): void
    {
        $message = $this->queue();
        $provider = new FakeChannelProvider();
        $handler = new SendOutboundMessageHandler(
            $this->store,
            new ChannelProviderRegistry([$provider]),
            self::getContainer()->get(\App\Module\Clients\Application\ChannelConnectionResolver::class),
        );
        $command = new SendOutboundMessage($this->administrator->organizationId(), $message->id());
        $provider->sendFailure = new \RuntimeException('Temporary provider failure.');

        try {
            $this->context->runWith($this->administrator->organizationId(), fn () => $handler($command));
            self::fail('The transient provider failure must be retried by Messenger.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Temporary provider failure.', $exception->getMessage());
        }

        $afterFailure = $this->context->runWith($this->administrator->organizationId(), fn () => $this->store->findOutbound($message->id()));
        self::assertSame('PENDING', $afterFailure?->status()->value);
        self::assertSame(1, $afterFailure?->attempts());

        $provider->sendFailure = null;
        $this->context->runWith($this->administrator->organizationId(), fn () => $handler($command));
        self::assertCount(1, $provider->sent);
        self::assertSame($message->id()->toRfc4122(), $provider->sent[0]->idempotencyKey);
        self::assertSame(['source' => 'test'], $provider->sent[0]->metadata);

        $sent = $this->context->runWith($this->administrator->organizationId(), fn () => $this->store->findOutbound($message->id()));
        self::assertSame('SENT', $sent?->status()->value);
        self::assertSame(2, $sent?->attempts());
    }

    public function testDeliveryStatusesAreCapabilityGuardedAndMonotonic(): void
    {
        $message = $this->queue();
        $message->markSent('provider-delivery-1');
        $this->context->runWith($this->administrator->organizationId(), fn () => $this->store->save($message));
        $consumer = new DeliveryStatusConsumer(
            $this->store,
            new ChannelProviderRegistry([new FakeChannelProvider(CommunicationProvider::MAX, new ChannelCapabilities(true, false, true, true, true))]),
        );

        $this->consumeDelivery($consumer, 'event-read', 'READ', '2026-09-14T12:00:00+00:00');
        self::assertSame('READ', $message->status()->value);
        self::assertSame('2026-09-14T12:00:00+00:00', $message->readAt()?->format(DATE_ATOM));

        $this->consumeDelivery($consumer, 'event-delivered-late', 'DELIVERED', '2026-09-14T11:00:00+00:00');
        self::assertSame('READ', $message->status()->value);
        self::assertSame('2026-09-14T12:00:00+00:00', $message->deliveredAt()?->format(DATE_ATOM));

        $unsupported = new DeliveryStatusConsumer($this->store, new ChannelProviderRegistry([new FakeChannelProvider()]));
        $this->consumeDelivery($unsupported, 'event-unsupported', 'FAILED', null);
        self::assertSame('READ', $message->status()->value);
    }

    public function testManualRetryResetsProviderDeliveryStateAndIsTenantScoped(): void
    {
        $message = $this->queue();
        $message->markSent('provider-retry-1');
        $message->fail('Temporary failure');
        $this->context->runWith($this->administrator->organizationId(), fn () => $this->store->save($message));
        $retry = new OutboundMessageRetryService($this->store);

        $this->context->runWith($this->administrator->organizationId(), fn () => $retry->retry($this->administrator, $message->id(), new \DateTimeImmutable('2026-09-14T13:00:00+00:00')));
        self::assertSame('PENDING', $message->status()->value);
        self::assertNull($message->providerMessageId());
        self::assertNull($message->sentAt());
        self::assertNull($message->lastError());

        $foreign = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Foreign retry', 'foreign-retry-'.bin2hex(random_bytes(4)).'@example.test', 'test-password', 'UTC');
        $this->expectException(\OutOfBoundsException::class);
        $this->context->runWith($foreign->organizationId(), fn () => $retry->retry($foreign, $message->id(), new \DateTimeImmutable()));
    }

    public function testProviderMessageIdentifierCannotBeReusedInsideTenant(): void
    {
        $first = $this->queue();
        $first->markSent('provider-unique-1');
        $this->context->runWith($this->administrator->organizationId(), fn () => $this->store->save($first));
        $second = $this->queue();
        $second->markSent('provider-unique-1');

        $this->expectException(\DomainException::class);
        $this->context->runWith($this->administrator->organizationId(), fn () => $this->store->save($second));
    }

    public function testDeliveryStatusCannotCrossTenantBoundary(): void
    {
        $message = $this->queue();
        $message->markSent('provider-tenant-1');
        $this->context->runWith($this->administrator->organizationId(), fn () => $this->store->save($message));
        $foreign = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Foreign delivery', 'foreign-delivery-'.bin2hex(random_bytes(4)).'@example.test', 'test-password', 'UTC');
        $event = NormalizedWebhookEvent::create($foreign->organization(), null, CommunicationProvider::MAX, 'foreign-event', [
            'kind' => 'DELIVERY_STATUS', 'providerMessageId' => 'provider-tenant-1', 'status' => 'READ',
        ]);
        $consumer = new DeliveryStatusConsumer(
            $this->store,
            new ChannelProviderRegistry([new FakeChannelProvider(CommunicationProvider::MAX, new ChannelCapabilities(true, false, true, true, true))]),
        );

        $this->expectException(\RuntimeException::class);
        $this->context->runWith($foreign->organizationId(), fn () => $consumer->consume($event));
    }

    private function consumeDelivery(DeliveryStatusConsumer $consumer, string $eventId, string $status, ?string $occurredAt): void
    {
        $payload = ['kind' => 'DELIVERY_STATUS', 'providerMessageId' => 'provider-delivery-1', 'status' => $status];
        if (null !== $occurredAt) {
            $payload['occurredAt'] = $occurredAt;
        }
        $event = NormalizedWebhookEvent::create($this->administrator->organization(), $this->channel->id(), CommunicationProvider::MAX, $eventId, $payload);
        $this->context->runWith($this->administrator->organizationId(), fn () => $consumer->consume($event));
    }

    private function queue(): OutboundMessage
    {
        return $this->context->runWith($this->administrator->organizationId(), fn () => $this->outbox->queue(
            'APPOINTMENT_REMINDER',
            $this->channel->id(),
            CommunicationProvider::MAX,
            '123456789',
            'Напоминание о занятии',
            ['date' => '2026-09-10'],
            [['label' => 'Будем', 'action' => 'confirm']],
            ['source' => 'test'],
            'appointment:'.(new \Symfony\Component\Uid\Ulid())->toRfc4122().':reminder',
        ));
    }
}
