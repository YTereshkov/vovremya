<?php

declare(strict_types=1);

namespace App\Module\Communications\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'communication_normalized_events')]
#[ORM\UniqueConstraint(name: 'uniq_normalized_events_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_normalized_events_external', columns: ['organization_id', 'provider', 'external_event_id'])]
#[ORM\Index(name: 'idx_normalized_events_tenant_created', columns: ['organization_id', 'created_at'])]
#[ORM\Index(name: 'idx_normalized_events_pending', columns: ['organization_id', 'status', 'available_at'])]
#[ORM\Index(name: 'idx_normalized_events_processing', columns: ['organization_id', 'status', 'processing_started_at'])]
final class NormalizedWebhookEvent implements OrganizationOwned
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(name: 'channel_connection_id', type: 'ulid', nullable: true)]
        private ?Ulid $channelConnectionId,
        #[ORM\Column(length: 16)]
        private string $provider,
        #[ORM\Column(name: 'external_event_id', length: 200)]
        private string $externalEventId,
        #[ORM\Column(type: 'jsonb')]
        private array $payload,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
        #[ORM\Column(enumType: NormalizedWebhookEventStatus::class, length: 16)]
        private NormalizedWebhookEventStatus $status,
        #[ORM\Column]
        private int $attempts,
        #[ORM\Column(name: 'available_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $availableAt,
        #[ORM\Column(name: 'processing_started_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?DateTimeImmutable $processingStartedAt,
        #[ORM\Column(name: 'processed_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?DateTimeImmutable $processedAt,
        #[ORM\Column(name: 'processing_error', length: 500, nullable: true)]
        private ?string $processingError,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function create(Organization $organization, ?Ulid $channelConnectionId, CommunicationProvider $provider, string $externalEventId, array $payload): self
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new self(new Ulid(), $organization, $channelConnectionId, $provider->value, $externalEventId, $payload, $now, NormalizedWebhookEventStatus::RECEIVED, 0, $now, null, null, null);
    }

    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function channelConnectionId(): ?Ulid { return $this->channelConnectionId; }
    public function provider(): CommunicationProvider { return CommunicationProvider::from($this->provider); }
    public function externalEventId(): string { return $this->externalEventId; }
    /** @return array<string, mixed> */
    public function payload(): array { return $this->payload; }
    public function status(): NormalizedWebhookEventStatus { return $this->status; }
    public function attempts(): int { return $this->attempts; }
    public function processingStartedAt(): ?DateTimeImmutable { return $this->processingStartedAt; }
    public function markProcessed(): void { $this->status = NormalizedWebhookEventStatus::PROCESSED; $this->processedAt = new DateTimeImmutable('now', new DateTimeZone('UTC')); $this->processingStartedAt = null; $this->processingError = null; }
    public function retry(string $error): void { $this->status = NormalizedWebhookEventStatus::RECEIVED; $this->processingStartedAt = null; $this->processingError = mb_substr(trim($error), 0, 500); }
    public function fail(string $error): void { $this->status = NormalizedWebhookEventStatus::FAILED; $this->processingStartedAt = null; $this->processingError = mb_substr(trim($error), 0, 500); }
}
