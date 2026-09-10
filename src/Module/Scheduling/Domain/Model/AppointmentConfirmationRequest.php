<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'appointment_confirmation_requests')]
#[ORM\UniqueConstraint(name: 'uniq_confirmation_requests_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_confirmation_requests_appointment', columns: ['organization_id', 'appointment_id'])]
#[ORM\Index(name: 'idx_confirmation_requests_status', columns: ['organization_id', 'status', 'requested_at'])]
final class AppointmentConfirmationRequest implements OrganizationOwned
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
        #[ORM\Column(length: 24, enumType: AppointmentConfirmationStatus::class)]
        private AppointmentConfirmationStatus $status,
        #[ORM\Column(name: 'requested_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $requestedAt,
        #[ORM\Column(name: 'responded_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $respondedAt,
        #[ORM\Column(name: 'no_response_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $noResponseAt,
        #[ORM\Column(name: 'reminder_sent_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $reminderSentAt,
    ) {
    }

    public static function create(Appointment $appointment, Ulid $channelConnectionId, \DateTimeImmutable $requestedAt): self
    {
        return new self(
            new Ulid(),
            $appointment->organization(),
            $appointment->id(),
            $channelConnectionId,
            AppointmentConfirmationStatus::Pending,
            $requestedAt->setTimezone(new \DateTimeZone('UTC')),
            null,
            null,
            null,
        );
    }

    public function markNoResponse(\DateTimeImmutable $at): void
    {
        if (AppointmentConfirmationStatus::Pending !== $this->status) {
            return;
        }
        $this->status = AppointmentConfirmationStatus::NoResponse;
        $this->noResponseAt = $at->setTimezone(new \DateTimeZone('UTC'));
    }

    public function markReminderSent(\DateTimeImmutable $at): void
    {
        $this->reminderSentAt ??= $at->setTimezone(new \DateTimeZone('UTC'));
    }

    public function id(): Ulid { return $this->id; }
    public function organization(): Organization { return $this->organization; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function appointmentId(): Ulid { return $this->appointmentId; }
    public function channelConnectionId(): Ulid { return $this->channelConnectionId; }
    public function status(): AppointmentConfirmationStatus { return $this->status; }
    public function reminderSentAt(): ?\DateTimeImmutable { return $this->reminderSentAt; }
}
