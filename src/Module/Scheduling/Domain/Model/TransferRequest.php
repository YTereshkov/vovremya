<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'transfer_requests')]
#[ORM\UniqueConstraint(name: 'uniq_transfer_requests_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\Index(name: 'idx_transfer_requests_appointment', columns: ['organization_id', 'appointment_id', 'created_at'])]
#[ORM\Index(name: 'idx_transfer_requests_status', columns: ['organization_id', 'status', 'created_at'])]
final class TransferRequest implements OrganizationOwned
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(name: 'appointment_id', type: 'ulid')]
        private Ulid $appointmentId,
        #[ORM\Column(name: 'channel_connection_id', type: 'ulid')]
        private Ulid $channelConnectionId,
        #[ORM\Column(length: 24, enumType: TransferRequestStatus::class)]
        private TransferRequestStatus $status,
        #[ORM\Column(name: 'decline_token_hash', length: 64, nullable: true)]
        private ?string $declineTokenHash,
        #[ORM\Column(name: 'new_appointment_id', type: 'ulid', nullable: true)]
        private ?Ulid $newAppointmentId,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'options_sent_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $optionsSentAt,
        #[ORM\Column(name: 'closed_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $closedAt,
    ) {
    }

    public static function open(Appointment $appointment, Ulid $channelConnectionId, \DateTimeImmutable $now): self
    {
        if (AppointmentPlanningStatus::Planned !== $appointment->planningStatus() || null !== $appointment->resultStatus()) {
            throw new \DomainException('Перенос доступен только для запланированного занятия.');
        }

        return new self(
            new Ulid(),
            $appointment->organization(),
            $appointment->id(),
            $channelConnectionId,
            TransferRequestStatus::AwaitingOptions,
            null,
            null,
            $now->setTimezone(new \DateTimeZone('UTC')),
            null,
            null,
        );
    }

    public function offer(string $declineToken, \DateTimeImmutable $now): void
    {
        if (TransferRequestStatus::AwaitingOptions !== $this->status) {
            throw new \DomainException('Варианты переноса уже предложены.');
        }
        self::assertToken($declineToken);
        $this->declineTokenHash = hash('sha256', $declineToken);
        $this->status = TransferRequestStatus::OptionsSent;
        $this->optionsSentAt = $now->setTimezone(new \DateTimeZone('UTC'));
    }

    public function complete(Ulid $newAppointmentId, \DateTimeImmutable $now): void
    {
        if (TransferRequestStatus::OptionsSent !== $this->status) {
            throw new \DomainException('Запрос переноса уже завершён.');
        }
        $this->status = TransferRequestStatus::Completed;
        $this->newAppointmentId = $newAppointmentId;
        $this->closedAt = $now->setTimezone(new \DateTimeZone('UTC'));
    }

    public function decline(string $token, Ulid $channelConnectionId, \DateTimeImmutable $now): bool
    {
        if (TransferRequestStatus::OptionsSent !== $this->status
            || !$this->channelConnectionId->equals($channelConnectionId)
            || null === $this->declineTokenHash
            || !hash_equals($this->declineTokenHash, hash('sha256', $token))) {
            return false;
        }
        $this->status = TransferRequestStatus::Declined;
        $this->closedAt = $now->setTimezone(new \DateTimeZone('UTC'));

        return true;
    }

    public function cancel(\DateTimeImmutable $now): void
    {
        if (!in_array($this->status, [TransferRequestStatus::AwaitingOptions, TransferRequestStatus::OptionsSent], true)) {
            throw new \DomainException('Завершённый запрос переноса нельзя отменить.');
        }
        $this->status = TransferRequestStatus::Cancelled;
        $this->closedAt = $now->setTimezone(new \DateTimeZone('UTC'));
    }

    public function id(): Ulid { return $this->id; }
    public function organization(): Organization { return $this->organization; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function appointmentId(): Ulid { return $this->appointmentId; }
    public function channelConnectionId(): Ulid { return $this->channelConnectionId; }
    public function status(): TransferRequestStatus { return $this->status; }
    public function newAppointmentId(): ?Ulid { return $this->newAppointmentId; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }

    private static function assertToken(string $token): void
    {
        if (16 > strlen($token)) {
            throw new \InvalidArgumentException('Transfer action token is too short.');
        }
    }
}
