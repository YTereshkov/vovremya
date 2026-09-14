<?php

declare(strict_types=1);

namespace App\Module\Waiting\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'permanent_place_offers')]
#[ORM\UniqueConstraint(name: 'uniq_permanent_place_offers_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\Index(name: 'idx_permanent_place_offers_client', columns: ['organization_id', 'client_id', 'created_at'])]
#[ORM\Index(name: 'idx_permanent_place_offers_status', columns: ['organization_id', 'status', 'created_at'])]
final class PermanentPlaceOffer implements OrganizationOwned
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(name: 'permanent_place_id', type: 'ulid')]
        private Ulid $permanentPlaceId,
        #[ORM\Column(name: 'waiting_list_entry_id', type: 'ulid')]
        private Ulid $waitingListEntryId,
        #[ORM\Column(name: 'client_id', type: 'ulid')]
        private Ulid $clientId,
        #[ORM\Column(name: 'client_name_snapshot', length: 160)]
        private string $clientName,
        #[ORM\Column(name: 'channel_connection_id', type: 'ulid')]
        private Ulid $channelConnectionId,
        #[ORM\Column(length: 16, enumType: PermanentPlaceOfferStatus::class)]
        private PermanentPlaceOfferStatus $status,
        #[ORM\Column(name: 'accept_token_hash', length: 64)]
        private string $acceptTokenHash,
        #[ORM\Column(name: 'decline_token_hash', length: 64)]
        private string $declineTokenHash,
        #[ORM\Column(name: 'resulting_regular_schedule_id', type: 'ulid', nullable: true)]
        private ?Ulid $resultingRegularScheduleId,
        #[ORM\Column(name: 'closed_reason', length: 48, nullable: true)]
        private ?string $closedReason,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'closed_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $closedAt,
    ) {
    }

    public static function create(PermanentPlace $place, WaitingListEntry $entry, string $clientName, Ulid $channelConnectionId, string $acceptToken, string $declineToken, \DateTimeImmutable $now): self
    {
        $clientName = trim($clientName);
        if (!$place->organizationId()->equals($entry->organizationId()) || !$entry->active()) {
            throw new \LogicException('Cannot create a permanent-place offer across organizations.');
        }
        if ('' === $clientName || 160 < mb_strlen($clientName)) {
            throw new \InvalidArgumentException('Некорректное имя клиента для предложения.');
        }
        if (16 > strlen($acceptToken) || 16 > strlen($declineToken)) {
            throw new \InvalidArgumentException('Offer action token is too short.');
        }

        return new self(
            new Ulid(),
            $place->organization(),
            $place->id(),
            $entry->id(),
            $entry->clientId(),
            $clientName,
            $channelConnectionId,
            PermanentPlaceOfferStatus::Active,
            hash('sha256', $acceptToken),
            hash('sha256', $declineToken),
            null,
            null,
            $now->setTimezone(new \DateTimeZone('UTC')),
            null,
        );
    }

    public function authorizeAccept(string $token, Ulid $channelConnectionId): bool
    {
        return PermanentPlaceOfferStatus::Active === $this->status
            && $this->channelConnectionId->equals($channelConnectionId)
            && hash_equals($this->acceptTokenHash, hash('sha256', $token));
    }

    public function authorizeDecline(string $token, Ulid $channelConnectionId): bool
    {
        return PermanentPlaceOfferStatus::Active === $this->status
            && $this->channelConnectionId->equals($channelConnectionId)
            && hash_equals($this->declineTokenHash, hash('sha256', $token));
    }

    public function accept(Ulid $scheduleId, \DateTimeImmutable $now): void
    {
        $this->close(PermanentPlaceOfferStatus::Accepted, 'CLIENT_ACCEPTED', $now);
        $this->resultingRegularScheduleId = $scheduleId;
    }

    public function decline(\DateTimeImmutable $now): void
    {
        $this->close(PermanentPlaceOfferStatus::Declined, 'CLIENT_DECLINED', $now);
    }

    public function cancel(string $reason, \DateTimeImmutable $now): void
    {
        $this->close(PermanentPlaceOfferStatus::Cancelled, $reason, $now);
    }

    private function close(PermanentPlaceOfferStatus $status, string $reason, \DateTimeImmutable $now): void
    {
        if (PermanentPlaceOfferStatus::Active !== $this->status) {
            throw new \DomainException('Предложение уже завершено.');
        }
        $this->status = $status;
        $this->closedReason = mb_substr(trim($reason), 0, 48);
        $this->closedAt = $now->setTimezone(new \DateTimeZone('UTC'));
    }

    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function permanentPlaceId(): Ulid { return $this->permanentPlaceId; }
    public function waitingListEntryId(): Ulid { return $this->waitingListEntryId; }
    public function clientId(): Ulid { return $this->clientId; }
    public function clientName(): string { return $this->clientName; }
    public function channelConnectionId(): Ulid { return $this->channelConnectionId; }
    public function status(): PermanentPlaceOfferStatus { return $this->status; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
}
