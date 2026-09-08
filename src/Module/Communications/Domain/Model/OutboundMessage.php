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
#[ORM\Table(name: 'communication_outbox')]
#[ORM\UniqueConstraint(name: 'uniq_communication_outbox_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\Index(name: 'idx_communication_outbox_pending', columns: ['organization_id', 'status', 'available_at'])]
#[ORM\Index(name: 'idx_communication_outbox_processing', columns: ['organization_id', 'status', 'processing_started_at'])]
#[ORM\Index(name: 'idx_communication_outbox_intent', columns: ['organization_id', 'notification_intent_id'])]
final class OutboundMessage implements OrganizationOwned
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(name: 'notification_intent_id', type: 'ulid')]
        private Ulid $notificationIntentId,
        #[ORM\Column(name: 'channel_connection_id', type: 'ulid', nullable: true)]
        private ?Ulid $channelConnectionId,
        #[ORM\Column(length: 16)]
        private string $provider,
        #[ORM\Column(name: 'recipient_address', length: 254)]
        private string $recipientAddress,
        #[ORM\Column(type: Types::TEXT)]
        private string $body,
        #[ORM\Column(type: 'jsonb')]
        private array $buttons,
        #[ORM\Column(type: 'jsonb')]
        private array $metadata,
        #[ORM\Column(enumType: OutboundMessageStatus::class, length: 16)]
        private OutboundMessageStatus $status,
        #[ORM\Column]
        private int $attempts,
        #[ORM\Column(name: 'available_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $availableAt,
        #[ORM\Column(name: 'processing_started_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?DateTimeImmutable $processingStartedAt,
        #[ORM\Column(name: 'published_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?DateTimeImmutable $publishedAt,
        #[ORM\Column(name: 'sent_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?DateTimeImmutable $sentAt,
        #[ORM\Column(name: 'provider_message_id', length: 200, nullable: true)]
        private ?string $providerMessageId,
        #[ORM\Column(name: 'last_error', length: 500, nullable: true)]
        private ?string $lastError,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $buttons
     *  @param array<string, mixed> $metadata
     */
    public static function create(
        Organization $organization,
        Ulid $notificationIntentId,
        ?Ulid $channelConnectionId,
        CommunicationProvider $provider,
        string $recipientAddress,
        string $body,
        array $buttons = [],
        array $metadata = [],
        ?DateTimeImmutable $availableAt = null,
    ): self {
        $recipientAddress = trim($recipientAddress);
        $body = trim($body);
        if ('' === $recipientAddress || 254 < strlen($recipientAddress)) {
            throw new \InvalidArgumentException('Адрес получателя должен содержать от 1 до 254 символов.');
        }
        if ('' === $body) {
            throw new \InvalidArgumentException('Текст сообщения не может быть пустым.');
        }

        return new self(
            new Ulid(),
            $organization,
            $notificationIntentId,
            $channelConnectionId,
            $provider->value,
            $recipientAddress,
            $body,
            $buttons,
            $metadata,
            OutboundMessageStatus::PENDING,
            0,
            $availableAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
            null,
            null,
            null,
            null,
            null,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }

    public function markPublished(): void
    {
        $this->publishedAt ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function markAttempted(): void
    {
        ++$this->attempts;
        $this->status = OutboundMessageStatus::PROCESSING;
        $this->processingStartedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function markSent(?string $providerMessageId = null): void
    {
        $this->status = OutboundMessageStatus::SENT;
        $this->providerMessageId = $providerMessageId;
        $this->sentAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->processingStartedAt = null;
        $this->lastError = null;
    }

    public function retry(string $error): void
    {
        $this->status = OutboundMessageStatus::PENDING;
        $this->processingStartedAt = null;
        $this->lastError = mb_substr(trim($error), 0, 500);
    }

    public function fail(string $error): void
    {
        $this->status = OutboundMessageStatus::FAILED;
        $this->processingStartedAt = null;
        $this->lastError = mb_substr(trim($error), 0, 500);
    }

    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function notificationIntentId(): Ulid { return $this->notificationIntentId; }
    public function channelConnectionId(): ?Ulid { return $this->channelConnectionId; }
    public function provider(): CommunicationProvider { return CommunicationProvider::from($this->provider); }
    public function recipientAddress(): string { return $this->recipientAddress; }
    public function body(): string { return $this->body; }
    /** @return array<string, mixed> */
    public function buttons(): array { return $this->buttons; }
    /** @return array<string, mixed> */
    public function metadata(): array { return $this->metadata; }
    public function status(): OutboundMessageStatus { return $this->status; }
    public function attempts(): int { return $this->attempts; }
}
