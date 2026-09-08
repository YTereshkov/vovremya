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
#[ORM\Table(name: 'notification_intents')]
#[ORM\UniqueConstraint(name: 'uniq_notification_intents_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_notification_intents_dedupe', columns: ['organization_id', 'dedupe_key'])]
#[ORM\Index(name: 'idx_notification_intents_pending', columns: ['organization_id', 'status', 'created_at'])]
final class NotificationIntent implements OrganizationOwned
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(length: 64)]
        private string $type,
        #[ORM\Column(name: 'recipient_channel_id', type: 'ulid', nullable: true)]
        private ?Ulid $recipientChannelId,
        #[ORM\Column(type: 'jsonb')]
        private array $payload,
        #[ORM\Column(name: 'dedupe_key', length: 160, nullable: true)]
        private ?string $dedupeKey,
        #[ORM\Column(enumType: NotificationIntentStatus::class, length: 16)]
        private NotificationIntentStatus $status,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function create(
        Organization $organization,
        string $type,
        ?Ulid $recipientChannelId,
        array $payload,
        ?string $dedupeKey = null,
    ): self {
        $type = trim($type);
        if ('' === $type || 64 < strlen($type)) {
            throw new \InvalidArgumentException('Тип уведомления должен содержать от 1 до 64 символов.');
        }
        if (null !== $dedupeKey) {
            $dedupeKey = trim($dedupeKey);
            if ('' === $dedupeKey || 160 < strlen($dedupeKey)) {
                throw new \InvalidArgumentException('Ключ дедупликации должен содержать от 1 до 160 символов.');
            }
        }

        return new self(
            new Ulid(),
            $organization,
            $type,
            $recipientChannelId,
            $payload,
            $dedupeKey,
            NotificationIntentStatus::PENDING,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }

    public function cancel(): void
    {
        $this->status = NotificationIntentStatus::CANCELLED;
    }

    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function type(): string { return $this->type; }
    public function recipientChannelId(): ?Ulid { return $this->recipientChannelId; }
    /** @return array<string, mixed> */
    public function payload(): array { return $this->payload; }
    public function dedupeKey(): ?string { return $this->dedupeKey; }
    public function status(): NotificationIntentStatus { return $this->status; }
}
