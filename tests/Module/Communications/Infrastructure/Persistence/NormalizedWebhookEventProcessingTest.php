<?php

declare(strict_types=1);

namespace App\Tests\Module\Communications\Infrastructure\Persistence;

use App\Module\Communications\Application\Message\ProcessNormalizedWebhookEvent;
use App\Module\Communications\Application\Message\ProcessNormalizedWebhookEventHandler;
use App\Module\Communications\Application\NormalizedWebhookEventConsumer;
use App\Module\Communications\Application\NormalizedWebhookEventSink;
use App\Module\Communications\Application\NormalizedWebhookEventStore;
use App\Module\Communications\Application\WebhookEvent;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Communications\Domain\Model\NormalizedWebhookEvent;
use App\Module\Organization\Application\OrganizationContext;
use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

final class NormalizedWebhookEventProcessingTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private OrganizationContext $context;
    private Ulid $organizationId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $administrator = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Normalized event test', 'normalized-'.bin2hex(random_bytes(4)).'@example.test', 'test-password', 'UTC');
        $this->organizationId = $administrator->organizationId();
        $this->context = self::getContainer()->get(OrganizationContext::class);
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $this->entityManager->clear();
        parent::tearDown();
    }

    public function testConsumerMutationsAndProcessedStateCommitAtomically(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('CREATE TEMPORARY TABLE normalized_event_markers (event_id UUID PRIMARY KEY) ON COMMIT DROP');
        $eventId = $this->record('atomic-event');
        $store = self::getContainer()->get(NormalizedWebhookEventStore::class);
        $markerConsumer = new class($connection) implements NormalizedWebhookEventConsumer {
            public function __construct(private readonly Connection $connection) {}
            public function consume(NormalizedWebhookEvent $event): void
            {
                $this->connection->executeStatement('INSERT INTO normalized_event_markers (event_id) VALUES (?)', [$event->id()->toRfc4122()]);
            }
        };
        $failingConsumer = new class implements NormalizedWebhookEventConsumer {
            public bool $fail = true;
            public function consume(NormalizedWebhookEvent $event): void
            {
                if ($this->fail) {
                    throw new \RuntimeException('Consumer failed after a mutation.');
                }
            }
        };
        $handler = new ProcessNormalizedWebhookEventHandler($store, [$markerConsumer, $failingConsumer]);
        $message = new ProcessNormalizedWebhookEvent($this->organizationId, $eventId);

        try {
            $this->context->runWith($this->organizationId, fn () => $handler($message));
            self::fail('The consumer failure must be propagated for Messenger retry.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Consumer failed after a mutation.', $exception->getMessage());
        }

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM normalized_event_markers'));
        self::assertSame('RECEIVED', $connection->fetchOne('SELECT status FROM communication_normalized_events WHERE id = ?', [$eventId->toRfc4122()]));

        $failingConsumer->fail = false;
        $this->context->runWith($this->organizationId, fn () => $handler($message));
        $this->context->runWith($this->organizationId, fn () => $handler($message));

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM normalized_event_markers'));
        self::assertSame('PROCESSED', $connection->fetchOne('SELECT status FROM communication_normalized_events WHERE id = ?', [$eventId->toRfc4122()]));
    }

    public function testDatabaseRejectsUnknownLifecycleStatus(): void
    {
        $eventId = $this->record('invalid-status');
        $this->assertCheckViolation(
            'UPDATE communication_normalized_events SET status = ? WHERE id = ?',
            ['UNKNOWN', $eventId->toRfc4122()],
        );
    }

    public function testDatabaseRejectsNegativeAttempts(): void
    {
        $eventId = $this->record('invalid-attempts');
        $this->assertCheckViolation(
            'UPDATE communication_normalized_events SET attempts = ? WHERE id = ?',
            [-1, $eventId->toRfc4122()],
        );
    }

    public function testLifecycleIndexesAndChecksExist(): void
    {
        $connection = $this->entityManager->getConnection();
        $indexes = $connection->fetchFirstColumn("SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'communication_normalized_events'");
        self::assertContains('idx_normalized_events_pending', $indexes);
        self::assertContains('idx_normalized_events_processing', $indexes);

        $checks = $connection->fetchFirstColumn(<<<'SQL'
            SELECT conname
            FROM pg_constraint
            WHERE conrelid = 'communication_normalized_events'::regclass
              AND contype = 'c'
            SQL);
        self::assertContains('chk_normalized_events_status', $checks);
        self::assertContains('chk_normalized_events_attempts', $checks);
    }

    private function record(string $externalEventId): Ulid
    {
        $sink = self::getContainer()->get(NormalizedWebhookEventSink::class);
        $event = new WebhookEvent(CommunicationProvider::MAX, $externalEventId, ['kind' => 'UNSUPPORTED']);
        $id = $this->context->runWith($this->organizationId, fn () => $sink->record($this->organizationId, null, $event));
        self::assertInstanceOf(Ulid::class, $id);

        return $id;
    }

    /** @param list<mixed> $parameters */
    private function assertCheckViolation(string $sql, array $parameters): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('SAVEPOINT normalized_event_invalid_state');
        try {
            $connection->executeStatement($sql, $parameters);
            self::fail('PostgreSQL must reject the invalid normalized-event lifecycle state.');
        } catch (DriverException) {
            self::addToAssertionCount(1);
        } finally {
            $connection->executeStatement('ROLLBACK TO SAVEPOINT normalized_event_invalid_state');
            $connection->executeStatement('RELEASE SAVEPOINT normalized_event_invalid_state');
        }
    }
}
