<?php

declare(strict_types=1);

namespace App\Module\Waiting\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'free_window_offers')]
#[ORM\UniqueConstraint(name: 'uniq_free_window_offers_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\Index(name: 'idx_free_window_offers_client', columns: ['organization_id', 'client_id', 'created_at'])]
#[ORM\Index(name: 'idx_free_window_offers_status', columns: ['organization_id', 'status', 'created_at'])]
final class FreeWindowOffer implements OrganizationOwned
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(name: 'free_window_id', type: 'ulid')]
        private Ulid $freeWindowId,
        #[ORM\Column(name: 'client_id', type: 'ulid')]
        private Ulid $clientId,
        #[ORM\Column(name: 'client_name_snapshot', length: 160)]
        private string $clientName,
        #[ORM\Column(name: 'channel_connection_id', type: 'ulid')]
        private Ulid $channelConnectionId,
        #[ORM\Column(name: 'target_type', length: 20, enumType: FreeWindowOfferTargetType::class)]
        private FreeWindowOfferTargetType $targetType,
        #[ORM\Column(name: 'candidate_appointment_id', type: 'ulid', nullable: true)]
        private ?Ulid $candidateAppointmentId,
        #[ORM\Column(name: 'waiting_list_entry_id', type: 'ulid', nullable: true)]
        private ?Ulid $waitingListEntryId,
        #[ORM\Column(name: 'service_id', type: 'ulid')]
        private Ulid $serviceId,
        #[ORM\Column(name: 'service_name_snapshot', length: 160)]
        private string $serviceName,
        #[ORM\Column(name: 'service_default_duration_snapshot', type: Types::SMALLINT)]
        private int $serviceDefaultDuration,
        #[ORM\Column(name: 'service_minimum_duration_snapshot', type: Types::SMALLINT, nullable: true)]
        private ?int $serviceMinimumDuration,
        #[ORM\Column(name: 'service_maximum_duration_snapshot', type: Types::SMALLINT, nullable: true)]
        private ?int $serviceMaximumDuration,
        #[ORM\Column(name: 'appointment_duration_minutes', type: Types::SMALLINT)]
        private int $appointmentDurationMinutes,
        #[ORM\Column(length: 16, enumType: FreeWindowOfferStatus::class)]
        private FreeWindowOfferStatus $status,
        #[ORM\Column(name: 'accept_token_hash', length: 64)]
        private string $acceptTokenHash,
        #[ORM\Column(name: 'decline_token_hash', length: 64)]
        private string $declineTokenHash,
        #[ORM\Column(name: 'resulting_appointment_id', type: 'ulid', nullable: true)]
        private ?Ulid $resultingAppointmentId,
        #[ORM\Column(name: 'closed_reason', length: 48, nullable: true)]
        private ?string $closedReason,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'closed_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $closedAt,
    ) {
    }

    public static function create(
        FreeWindow $window,
        Ulid $clientId,
        string $clientName,
        Ulid $channelConnectionId,
        FreeWindowOfferTargetType $targetType,
        ?Ulid $candidateAppointmentId,
        ?Ulid $waitingListEntryId,
        int $serviceDefaultDuration,
        ?int $serviceMinimumDuration,
        ?int $serviceMaximumDuration,
        int $appointmentDurationMinutes,
        string $acceptToken,
        string $declineToken,
        \DateTimeImmutable $now,
    ): self {
        $clientName = trim($clientName);
        if ('' === $clientName || 160 < mb_strlen($clientName)) {
            throw new \InvalidArgumentException('Некорректное имя клиента для предложения.');
        }
        if ((FreeWindowOfferTargetType::MoveEarlier === $targetType) !== (null !== $candidateAppointmentId)
            || (FreeWindowOfferTargetType::WaitingList === $targetType) !== (null !== $waitingListEntryId)) {
            throw new \InvalidArgumentException('Некорректный получатель предложения.');
        }
        if (16 > strlen($acceptToken) || 16 > strlen($declineToken)) {
            throw new \InvalidArgumentException('Offer action token is too short.');
        }
        if (1 > $appointmentDurationMinutes || $window->startsAt()->modify(sprintf('+%d minutes', $appointmentDurationMinutes)) > $window->endsAt()) {
            throw new \InvalidArgumentException('Занятие не помещается в свободное окно.');
        }

        return new self(
            new Ulid(),
            $window->organization(),
            $window->id(),
            $clientId,
            $clientName,
            $channelConnectionId,
            $targetType,
            $candidateAppointmentId,
            $waitingListEntryId,
            $window->serviceId(),
            $window->serviceName(),
            $serviceDefaultDuration,
            $serviceMinimumDuration,
            $serviceMaximumDuration,
            $appointmentDurationMinutes,
            FreeWindowOfferStatus::Active,
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
        return FreeWindowOfferStatus::Active === $this->status
            && $this->channelConnectionId->equals($channelConnectionId)
            && hash_equals($this->acceptTokenHash, hash('sha256', $token));
    }

    public function authorizeDecline(string $token, Ulid $channelConnectionId): bool
    {
        return FreeWindowOfferStatus::Active === $this->status
            && $this->channelConnectionId->equals($channelConnectionId)
            && hash_equals($this->declineTokenHash, hash('sha256', $token));
    }

    public function accept(Ulid $appointmentId, \DateTimeImmutable $now): void
    {
        $this->close(FreeWindowOfferStatus::Accepted, 'CLIENT_ACCEPTED', $now);
        $this->resultingAppointmentId = $appointmentId;
    }

    public function decline(\DateTimeImmutable $now): void
    {
        $this->close(FreeWindowOfferStatus::Declined, 'CLIENT_DECLINED', $now);
    }

    public function cancel(string $reason, \DateTimeImmutable $now): void
    {
        $this->close(FreeWindowOfferStatus::Cancelled, $reason, $now);
    }

    private function close(FreeWindowOfferStatus $status, string $reason, \DateTimeImmutable $now): void
    {
        if (FreeWindowOfferStatus::Active !== $this->status) {
            throw new \DomainException('Предложение уже завершено.');
        }
        $this->status = $status;
        $this->closedReason = mb_substr(trim($reason), 0, 48);
        $this->closedAt = $now->setTimezone(new \DateTimeZone('UTC'));
    }

    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function freeWindowId(): Ulid { return $this->freeWindowId; }
    public function clientId(): Ulid { return $this->clientId; }
    public function clientName(): string { return $this->clientName; }
    public function channelConnectionId(): Ulid { return $this->channelConnectionId; }
    public function targetType(): FreeWindowOfferTargetType { return $this->targetType; }
    public function candidateAppointmentId(): ?Ulid { return $this->candidateAppointmentId; }
    public function waitingListEntryId(): ?Ulid { return $this->waitingListEntryId; }
    public function serviceId(): Ulid { return $this->serviceId; }
    public function serviceName(): string { return $this->serviceName; }
    public function serviceDefaultDuration(): int { return $this->serviceDefaultDuration; }
    public function serviceMinimumDuration(): ?int { return $this->serviceMinimumDuration; }
    public function serviceMaximumDuration(): ?int { return $this->serviceMaximumDuration; }
    public function appointmentDurationMinutes(): int { return $this->appointmentDurationMinutes; }
    public function status(): FreeWindowOfferStatus { return $this->status; }
    public function resultingAppointmentId(): ?Ulid { return $this->resultingAppointmentId; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
}
