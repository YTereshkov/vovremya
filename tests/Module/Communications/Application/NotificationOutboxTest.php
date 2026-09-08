<?php

declare(strict_types=1);

namespace App\Tests\Module\Communications\Application;

use App\Module\Communications\Application\NotificationOutbox;
use App\Module\Communications\Application\CommunicationStore;
use App\Module\Communications\Application\ChannelProviderRegistry;
use App\Module\Communications\Application\Message\SendOutboundMessage;
use App\Module\Communications\Application\Message\SendOutboundMessageHandler;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Communications\Domain\Model\NotificationIntent;
use App\Module\Communications\Domain\Model\OutboundMessage;
use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
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

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->administrator = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Outbox test', 'outbox-'.bin2hex(random_bytes(4)).'@example.test', 'test-password', 'Europe/Moscow');
        $this->context = self::getContainer()->get(OrganizationContext::class);
        $this->store = self::getContainer()->get(CommunicationStore::class);
        $this->outbox = new NotificationOutbox($this->entityManager, $this->store, $this->context);
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

    public function testSendHandlerRetriesAndUsesStableIdempotencyKey(): void
    {
        $message = $this->queue();
        $provider = new FakeChannelProvider();
        $handler = new SendOutboundMessageHandler($this->store, new ChannelProviderRegistry([$provider]));
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

        $sent = $this->context->runWith($this->administrator->organizationId(), fn () => $this->store->findOutbound($message->id()));
        self::assertSame('SENT', $sent?->status()->value);
        self::assertSame(2, $sent?->attempts());
    }

    private function queue(): OutboundMessage
    {
        return $this->context->runWith($this->administrator->organizationId(), fn () => $this->outbox->queue(
            'APPOINTMENT_REMINDER',
            null,
            CommunicationProvider::MAX,
            '+79990000000',
            'Напоминание о занятии',
            ['date' => '2026-09-10'],
            [['label' => 'Будем', 'action' => 'confirm']],
            ['source' => 'test'],
            'appointment:'.(new \Symfony\Component\Uid\Ulid())->toRfc4122().':reminder',
        ));
    }
}
