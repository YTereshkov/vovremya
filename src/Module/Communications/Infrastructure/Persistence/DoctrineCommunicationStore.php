<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Persistence;

use App\Module\Communications\Application\CommunicationStore;
use App\Module\Communications\Domain\Model\NotificationIntent;
use App\Module\Communications\Domain\Model\NotificationIntentStatus;
use App\Module\Communications\Domain\Model\OutboundMessage;
use App\Module\Communications\Domain\Model\OutboundMessageStatus;
use App\Module\Communications\Domain\Model\WebhookInbox;
use App\Module\Communications\Domain\Model\WebhookInboxStatus;
use App\Module\Organization\Application\OrganizationContext;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineCommunicationStore implements CommunicationStore
{
    private const PROCESSING_LEASE = '5 minutes';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrganizationContext $organizationContext,
    ) {
    }

    public function pendingOutbound(int $limit = 50): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->entityManager->createQueryBuilder()
            ->select('item')->from(OutboundMessage::class, 'item')
            ->innerJoin(NotificationIntent::class, 'intent', 'ON', 'intent.id = item.notificationIntentId')
            ->andWhere('IDENTITY(item.organization) = :organization')
            ->andWhere('IDENTITY(intent.organization) = :organization')
            ->andWhere('intent.status = :intent_status')
            ->andWhere('(item.status = :pending_status AND item.availableAt <= :now) OR (item.status = :processing_status AND item.processingStartedAt <= :stale_before)')
            ->setParameter('organization', $this->organizationContext->currentId(), 'ulid')
            ->setParameter('intent_status', NotificationIntentStatus::PENDING->value)
            ->setParameter('pending_status', OutboundMessageStatus::PENDING->value)
            ->setParameter('processing_status', OutboundMessageStatus::PROCESSING->value)
            ->setParameter('now', $now)
            ->setParameter('stale_before', $now->modify('-'.self::PROCESSING_LEASE))
            ->orderBy('item.availableAt', 'ASC')->addOrderBy('item.id', 'ASC')
            ->setMaxResults(max(1, min($limit, 500)))
            ->getQuery()->getResult();
    }

    public function pendingWebhooks(int $limit = 50): array
    {
        $staleBefore = new \DateTimeImmutable('-'.self::PROCESSING_LEASE, new \DateTimeZone('UTC'));

        return $this->entityManager->createQueryBuilder()
            ->select('item')->from(WebhookInbox::class, 'item')
            ->andWhere('IDENTITY(item.organization) = :organization')
            ->andWhere('item.status = :received_status OR (item.status = :processing_status AND item.processingStartedAt <= :stale_before)')
            ->setParameter('organization', $this->organizationContext->currentId(), 'ulid')
            ->setParameter('received_status', WebhookInboxStatus::RECEIVED->value)
            ->setParameter('processing_status', WebhookInboxStatus::PROCESSING->value)
            ->setParameter('stale_before', $staleBefore)
            ->orderBy('item.receivedAt', 'ASC')->addOrderBy('item.id', 'ASC')
            ->setMaxResults(max(1, min($limit, 500)))
            ->getQuery()->getResult();
    }

    public function findOutbound(Ulid $id): ?OutboundMessage
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('item')->from(OutboundMessage::class, 'item')
            ->andWhere('IDENTITY(item.organization) = :organization')
            ->andWhere('item.id = :id')
            ->setParameter('organization', $this->organizationContext->currentId(), 'ulid')
            ->setParameter('id', $id, 'ulid')
            ->getQuery()->getOneOrNullResult();

        return $result instanceof OutboundMessage ? $result : null;
    }

    public function findOutboundByProviderMessageId(string $provider, string $providerMessageId): ?OutboundMessage
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('item')->from(OutboundMessage::class, 'item')
            ->andWhere('IDENTITY(item.organization) = :organization')
            ->andWhere('item.provider = :provider')
            ->andWhere('item.providerMessageId = :message')
            ->setParameter('organization', $this->organizationContext->currentId(), 'ulid')
            ->setParameter('provider', $provider)
            ->setParameter('message', $providerMessageId)
            ->getQuery()->getOneOrNullResult();

        return $result instanceof OutboundMessage ? $result : null;
    }

    public function findWebhook(string $provider, string $externalEventId): ?WebhookInbox
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('item')->from(WebhookInbox::class, 'item')
            ->andWhere('IDENTITY(item.organization) = :organization')
            ->andWhere('item.provider = :provider')
            ->andWhere('item.externalEventId = :event')
            ->setParameter('organization', $this->organizationContext->currentId(), 'ulid')
            ->setParameter('provider', $provider)
            ->setParameter('event', $externalEventId)
            ->getQuery()->getOneOrNullResult();

        return $result instanceof WebhookInbox ? $result : null;
    }

    public function findWebhookById(Ulid $id): ?WebhookInbox
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('item')->from(WebhookInbox::class, 'item')
            ->andWhere('IDENTITY(item.organization) = :organization')
            ->andWhere('item.id = :id')
            ->setParameter('organization', $this->organizationContext->currentId(), 'ulid')
            ->setParameter('id', $id, 'ulid')
            ->getQuery()->getOneOrNullResult();

        return $result instanceof WebhookInbox ? $result : null;
    }

    public function recordWebhook(WebhookInbox $inbox): array
    {
        if (!OrganizationIsolation::belongsTo($this->organizationContext->currentId(), $inbox)) {
            throw new \LogicException('Cannot write outside the current organization.');
        }

        $insertedId = $this->entityManager->getConnection()->fetchOne(
            <<<'SQL'
                INSERT INTO communication_webhook_inbox
                    (id, organization_id, channel_connection_id, provider, external_event_id, payload, status, attempts, received_at)
                VALUES
                    (:id, :organization_id, :channel_connection_id, :provider, :external_event_id, CAST(:payload AS JSONB), 'RECEIVED', 0, :received_at)
                ON CONFLICT (organization_id, provider, external_event_id) DO NOTHING
                RETURNING id
                SQL,
            [
                'id' => $inbox->id()->toRfc4122(),
                'organization_id' => $inbox->organizationId()->toRfc4122(),
                'channel_connection_id' => $inbox->channelConnectionId()?->toRfc4122(),
                'provider' => $inbox->provider()->value,
                'external_event_id' => $inbox->externalEventId(),
                'payload' => json_encode($inbox->payload(), JSON_THROW_ON_ERROR),
                'received_at' => $inbox->receivedAt()->format('Y-m-d H:i:s.uP'),
            ],
        );

        if (false !== $insertedId) {
            return ['inbox' => $inbox, 'duplicate' => false];
        }

        $existing = $this->findWebhook($inbox->provider()->value, $inbox->externalEventId());
        if (null === $existing) {
            throw new \RuntimeException('Webhook conflict row was not found.');
        }

        return ['inbox' => $existing, 'duplicate' => true];
    }

    public function save(\App\Shared\Domain\MultiTenancy\OrganizationOwned ...$entities): void
    {
        foreach ($entities as $entity) {
            if (!OrganizationIsolation::belongsTo($this->organizationContext->currentId(), $entity)) {
                throw new \LogicException('Cannot write outside the current organization.');
            }
            $this->entityManager->persist($entity);
        }

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            throw new \DomainException('Такая коммуникация уже существует.', 0, $exception);
        }
    }

    public function claimOutbound(Ulid $id): ?OutboundMessage
    {
        $affected = $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE communication_outbox AS item
                SET status = 'PROCESSING', attempts = attempts + 1, processing_started_at = clock_timestamp()
                WHERE item.organization_id = :organization_id
                  AND item.id = :id
                  AND (
                    (item.status = 'PENDING' AND item.available_at <= clock_timestamp())
                    OR (item.status = 'PROCESSING' AND item.processing_started_at <= clock_timestamp() - INTERVAL '5 minutes')
                  )
                  AND EXISTS (
                    SELECT 1 FROM notification_intents AS intent
                    WHERE intent.organization_id = item.organization_id
                      AND intent.id = item.notification_intent_id
                      AND intent.status = 'PENDING'
                  )
                SQL,
            [
                'organization_id' => $this->organizationContext->currentId()->toRfc4122(),
                'id' => $id->toRfc4122(),
            ],
        );

        if (1 !== $affected) {
            return null;
        }

        $outbound = $this->findOutbound($id);
        if (null !== $outbound) {
            $this->entityManager->refresh($outbound);
        }

        return $outbound;
    }

    public function claimWebhook(Ulid $id): ?WebhookInbox
    {
        $affected = $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE communication_webhook_inbox
                SET status = 'PROCESSING', attempts = attempts + 1, processing_started_at = clock_timestamp()
                WHERE organization_id = :organization_id
                  AND id = :id
                  AND (
                    status = 'RECEIVED'
                    OR (status = 'PROCESSING' AND processing_started_at <= clock_timestamp() - INTERVAL '5 minutes')
                  )
                SQL,
            [
                'organization_id' => $this->organizationContext->currentId()->toRfc4122(),
                'id' => $id->toRfc4122(),
            ],
        );

        if (1 !== $affected) {
            return null;
        }

        $inbox = $this->findWebhookById($id);
        if (null !== $inbox) {
            $this->entityManager->refresh($inbox);
        }

        return $inbox;
    }

    public function transactional(callable $operation): mixed
    {
        return $this->entityManager->wrapInTransaction($operation);
    }
}
