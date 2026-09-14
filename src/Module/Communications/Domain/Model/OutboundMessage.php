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
#[ORM\UniqueConstraint(name: 'uniq_communication_outbox_provider_message', columns: ['organization_id', 'provider', 'provider_message_id'], options: ['where' => '(provider_message_id IS NOT NULL)'])]
#[ORM\Index(name: 'idx_communication_outbox_pending', columns: ['organization_id', 'status', 'available_at'])]
#[ORM\Index(name: 'idx_communication_outbox_processing', columns: ['organization_id', 'status', 'processing_started_at'])]
#[ORM\Index(name: 'idx_communication_outbox_intent', columns: ['organization_id', 'notification_intent_id'])]
#[ORM\Index(name: 'idx_communication_outbox_attention', columns: ['organization_id', 'status', 'created_at'])]
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
        #[ORM\Column(name: 'delivered_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?DateTimeImmutable $deliveredAt,
        #[ORM\Column(name: 'read_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?DateTimeImmutable $readAt,
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
        if (null === $channelConnectionId) {
            throw new \DomainException('Для внешнего сообщения требуется подключённый канал.');
        }
        self::assertMetadata($metadata);
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
        if (
            null !== $providerMessageId
            && null !== $this->providerMessageId
            && $providerMessageId !== $this->providerMessageId
        ) {
            throw new \DomainException('Идентификатор сообщения провайдера уже назначен.');
        }

        if (!in_array($this->status, [OutboundMessageStatus::DELIVERED, OutboundMessageStatus::READ], true)) {
            $this->status = OutboundMessageStatus::SENT;
        }
        $this->providerMessageId ??= $providerMessageId;
        $this->sentAt ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->processingStartedAt = null;
        $this->lastError = null;
    }

    public function markDelivered(DateTimeImmutable $at): void
    {
        if (OutboundMessageStatus::READ === $this->status) {
            return;
        }
        if (!in_array($this->status, [OutboundMessageStatus::SENT, OutboundMessageStatus::DELIVERED], true)) {
            throw new \DomainException('Статус доставки нельзя применить к неотправленному сообщению.');
        }
        $this->status = OutboundMessageStatus::DELIVERED;
        $this->deliveredAt ??= $at->setTimezone(new DateTimeZone('UTC'));
    }

    public function markRead(DateTimeImmutable $at): void
    {
        if (OutboundMessageStatus::READ === $this->status) {
            return;
        }
        if (!in_array($this->status, [OutboundMessageStatus::SENT, OutboundMessageStatus::DELIVERED], true)) {
            throw new \DomainException('Статус прочтения нельзя применить к неотправленному сообщению.');
        }
        $this->status = OutboundMessageStatus::READ;
        $this->deliveredAt ??= $at->setTimezone(new DateTimeZone('UTC'));
        $this->readAt ??= $at->setTimezone(new DateTimeZone('UTC'));
    }

    public function markDeliveryFailed(string $error): void
    {
        if (in_array($this->status, [OutboundMessageStatus::DELIVERED, OutboundMessageStatus::READ], true)) {
            return;
        }
        if (OutboundMessageStatus::SENT !== $this->status) {
            throw new \DomainException('Ошибка доставки относится только к отправленному сообщению.');
        }
        $this->fail($error);
    }

    public function retryManually(DateTimeImmutable $at): void
    {
        if (OutboundMessageStatus::FAILED !== $this->status) {
            throw new \DomainException('Повторить можно только сообщение с ошибкой.');
        }
        $this->status = OutboundMessageStatus::PENDING;
        $this->attempts = 0;
        $this->availableAt = $at->setTimezone(new DateTimeZone('UTC'));
        $this->processingStartedAt = null;
        $this->publishedAt = null;
        $this->sentAt = null;
        $this->deliveredAt = null;
        $this->readAt = null;
        $this->providerMessageId = null;
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
    public function sentAt(): ?DateTimeImmutable { return $this->sentAt; }
    public function deliveredAt(): ?DateTimeImmutable { return $this->deliveredAt; }
    public function readAt(): ?DateTimeImmutable { return $this->readAt; }
    public function providerMessageId(): ?string { return $this->providerMessageId; }
    public function lastError(): ?string { return $this->lastError; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }

    /** @param array<string, mixed> $metadata */
    public static function assertMetadata(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            // Part 31 has no provider-specific safe metadata contract yet.
            // Keep only the foundation's diagnostic source marker and reject
            // nested/unknown values before they can reach durable storage.
            if ('source' !== $key || !is_string($value) || '' === trim($value) || 100 < strlen($value)) {
                throw new \InvalidArgumentException('Разрешено только безопасное текстовое поле metadata source.');
            }
        }
    }
}
