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
#[ORM\Table(name: 'communication_webhook_inbox')]
#[ORM\UniqueConstraint(name: 'uniq_communication_webhook_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_communication_webhook_event', columns: ['organization_id', 'provider', 'external_event_id'])]
#[ORM\Index(name: 'idx_communication_webhook_status', columns: ['organization_id', 'status', 'received_at'])]
#[ORM\Index(name: 'idx_communication_webhook_processing', columns: ['organization_id', 'status', 'processing_started_at'])]
#[ORM\Index(name: 'idx_communication_webhook_connection', columns: ['organization_id', 'channel_connection_id'])]
final class WebhookInbox implements OrganizationOwned
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(length: 16)]
        private string $provider,
        #[ORM\Column(name: 'external_event_id', length: 200)]
        private string $externalEventId,
        #[ORM\Column(type: 'jsonb')]
        private array $payload,
        #[ORM\Column(name: 'channel_connection_id', type: 'ulid', nullable: true)]
        private ?Ulid $channelConnectionId,
        #[ORM\Column(enumType: WebhookInboxStatus::class, length: 16)]
        private WebhookInboxStatus $status,
        #[ORM\Column]
        private int $attempts,
        #[ORM\Column(name: 'received_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $receivedAt,
        #[ORM\Column(name: 'processing_started_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?DateTimeImmutable $processingStartedAt,
        #[ORM\Column(name: 'processed_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?DateTimeImmutable $processedAt,
        #[ORM\Column(name: 'processing_error', length: 500, nullable: true)]
        private ?string $processingError,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function receive(
        Organization $organization,
        CommunicationProvider $provider,
        string $externalEventId,
        array $payload,
        ?Ulid $channelConnectionId = null,
    ): self {
        $externalEventId = trim($externalEventId);
        if ('' === $externalEventId || 200 < strlen($externalEventId)) {
            throw new \InvalidArgumentException('Идентификатор события webhook должен содержать от 1 до 200 символов.');
        }

        return new self(
            new Ulid(),
            $organization,
            $provider->value,
            $externalEventId,
            $payload,
            $channelConnectionId,
            WebhookInboxStatus::RECEIVED,
            0,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            null,
            null,
            null,
        );
    }

    public function markProcessed(): void
    {
        $this->status = WebhookInboxStatus::PROCESSED;
        $this->processedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->processingStartedAt = null;
        $this->processingError = null;
    }

    public function retry(string $error): void
    {
        $this->status = WebhookInboxStatus::RECEIVED;
        $this->processingStartedAt = null;
        $this->processingError = mb_substr(trim($error), 0, 500);
    }

    public function markFailed(string $error): void
    {
        $this->status = WebhookInboxStatus::FAILED;
        $this->processingStartedAt = null;
        $this->processingError = mb_substr(trim($error), 0, 500);
    }

    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function provider(): CommunicationProvider { return CommunicationProvider::from($this->provider); }
    public function externalEventId(): string { return $this->externalEventId; }
    /** @return array<string, mixed> */
    public function payload(): array { return $this->payload; }
    public function status(): WebhookInboxStatus { return $this->status; }
    public function channelConnectionId(): ?Ulid { return $this->channelConnectionId; }
    public function attempts(): int { return $this->attempts; }
    public function receivedAt(): DateTimeImmutable { return $this->receivedAt; }
}
