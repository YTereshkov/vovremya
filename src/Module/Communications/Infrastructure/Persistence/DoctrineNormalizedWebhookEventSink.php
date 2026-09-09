<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Persistence;

use App\Module\Communications\Application\NormalizedWebhookEventSink;
use App\Module\Communications\Application\NormalizedWebhookEventStore;
use App\Module\Communications\Application\WebhookEvent;
use App\Module\Communications\Domain\Model\NormalizedWebhookEvent;
use App\Module\Organization\Application\OrganizationContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineNormalizedWebhookEventSink implements NormalizedWebhookEventSink, NormalizedWebhookEventStore
{
    public function __construct(private EntityManagerInterface $entityManager, private OrganizationContext $organizationContext)
    {
    }

    public function record(Ulid $organizationId, ?Ulid $channelConnectionId, WebhookEvent $event): ?Ulid
    {
        if (!$organizationId->equals($this->organizationContext->currentId())) {
            throw new \LogicException('Cannot record normalized event outside current organization.');
        }
        $id = (new Ulid())->toRfc4122();
        $inserted = $this->entityManager->getConnection()->fetchOne(
            <<<'SQL'
                INSERT INTO communication_normalized_events
                    (id, organization_id, channel_connection_id, provider, external_event_id, payload, created_at, status, attempts, available_at)
                VALUES
                    (:id, :organization_id, :channel_connection_id, :provider, :external_event_id, CAST(:payload AS JSONB), :created_at, 'RECEIVED', 0, :created_at)
                ON CONFLICT (organization_id, provider, external_event_id) DO NOTHING
                RETURNING id
                SQL,
            [
                'id' => $id,
                'organization_id' => $organizationId->toRfc4122(),
                'channel_connection_id' => $channelConnectionId?->toRfc4122(),
                'provider' => $event->provider->value,
                'external_event_id' => $event->externalEventId,
                'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR),
                'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.uP'),
            ],
        );

        return false === $inserted ? null : Ulid::fromString((string) $inserted);
    }

    public function pending(int $limit = 50): array
    {
        $stale = new \DateTimeImmutable('-5 minutes', new \DateTimeZone('UTC'));
        return $this->entityManager->createQueryBuilder()->select('item')->from(\App\Module\Communications\Domain\Model\NormalizedWebhookEvent::class, 'item')
            ->andWhere('IDENTITY(item.organization) = :organization')
            ->andWhere('(item.status = :received OR (item.status = :processing AND item.processingStartedAt <= :stale))')
            ->setParameter('organization', $this->organizationContext->currentId(), 'ulid')
            ->setParameter('received', \App\Module\Communications\Domain\Model\NormalizedWebhookEventStatus::RECEIVED->value)
            ->setParameter('processing', \App\Module\Communications\Domain\Model\NormalizedWebhookEventStatus::PROCESSING->value)
            ->setParameter('stale', $stale)
            ->orderBy('item.availableAt', 'ASC')->setMaxResults(max(1, min($limit, 500)))
            ->getQuery()->getResult();
    }

    public function claim(Ulid $id): ?\App\Module\Communications\Domain\Model\NormalizedWebhookEvent
    {
        $affected = $this->entityManager->getConnection()->executeStatement(
            "UPDATE communication_normalized_events SET status = 'PROCESSING', attempts = attempts + 1, processing_started_at = clock_timestamp() WHERE organization_id = :organization AND id = :id AND (status = 'RECEIVED' OR (status = 'PROCESSING' AND processing_started_at <= clock_timestamp() - INTERVAL '5 minutes'))",
            ['organization' => $this->organizationContext->currentId()->toRfc4122(), 'id' => $id->toRfc4122()],
        );
        if (1 !== $affected) {
            return null;
        }
        $event = $this->find($id);
        if (null !== $event) {
            $this->entityManager->refresh($event);
        }
        return $event;
    }

    public function find(Ulid $id): ?\App\Module\Communications\Domain\Model\NormalizedWebhookEvent
    {
        $event = $this->entityManager->createQueryBuilder()->select('item')->from(\App\Module\Communications\Domain\Model\NormalizedWebhookEvent::class, 'item')
            ->andWhere('IDENTITY(item.organization) = :organization')->andWhere('item.id = :id')
            ->setParameter('organization', $this->organizationContext->currentId(), 'ulid')->setParameter('id', $id, 'ulid')
            ->getQuery()->getOneOrNullResult();
        return $event instanceof \App\Module\Communications\Domain\Model\NormalizedWebhookEvent ? $event : null;
    }

    public function save(\App\Shared\Domain\MultiTenancy\OrganizationOwned ...$entities): void
    {
        foreach ($entities as $entity) {
            if (!$entity->organizationId()->equals($this->organizationContext->currentId())) {
                throw new \LogicException('Cannot write normalized event outside current organization.');
            }
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    public function completeAtomically(NormalizedWebhookEvent $event, callable $operation): void
    {
        if (!$event->organizationId()->equals($this->organizationContext->currentId())) {
            throw new \LogicException('Cannot process normalized event outside current organization.');
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $currentAttempt = $connection->fetchOne(
                "SELECT attempts FROM communication_normalized_events WHERE organization_id = :organization AND id = :id AND status = 'PROCESSING' FOR UPDATE",
                [
                    'organization' => $this->organizationContext->currentId()->toRfc4122(),
                    'id' => $event->id()->toRfc4122(),
                ],
            );
            if (false === $currentAttempt || (int) $currentAttempt !== $event->attempts()) {
                throw new \RuntimeException('Normalized event processing lease is no longer owned by this worker.');
            }
            $operation();
            $event->markProcessed();
            $this->entityManager->persist($event);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            // Discard changes tracked by consumers after the database rollback.
            $this->entityManager->clear();

            throw $exception;
        }
    }

    public function recordProcessingFailure(Ulid $id, int $attempt, string $error, int $maxAttempts = 3): void
    {
        $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE communication_normalized_events
                SET status = CASE WHEN attempts >= :max_attempts THEN 'FAILED' ELSE 'RECEIVED' END,
                    processing_started_at = NULL,
                    processing_error = :processing_error
                WHERE organization_id = :organization
                  AND id = :id
                  AND status = 'PROCESSING'
                  AND attempts = :attempt
                SQL,
            [
                'max_attempts' => max(1, $maxAttempts),
                'processing_error' => mb_substr(trim($error), 0, 500),
                'organization' => $this->organizationContext->currentId()->toRfc4122(),
                'id' => $id->toRfc4122(),
                'attempt' => $attempt,
            ],
        );
    }
}
