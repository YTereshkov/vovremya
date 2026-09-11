<?php

declare(strict_types=1);

namespace App\Module\Waiting\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'free_windows')]
#[ORM\UniqueConstraint(name: 'uniq_free_windows_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_free_windows_source', columns: ['organization_id', 'source_appointment_id'])]
#[ORM\Index(name: 'idx_free_windows_open', columns: ['organization_id', 'status', 'starts_at'])]
final class FreeWindow implements OrganizationOwned
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(name: 'source_appointment_id', type: 'ulid')]
        private Ulid $sourceAppointmentId,
        #[ORM\Column(name: 'specialist_id', type: 'ulid')]
        private Ulid $specialistId,
        #[ORM\Column(name: 'service_id', type: 'ulid')]
        private Ulid $serviceId,
        #[ORM\Column(name: 'service_name_snapshot', length: 160)]
        private string $serviceName,
        #[ORM\Column(name: 'duration_minutes', type: Types::SMALLINT)]
        private int $durationMinutes,
        #[ORM\Column(name: 'starts_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $startsAt,
        #[ORM\Column(name: 'ends_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $endsAt,
        #[ORM\Column(length: 16, enumType: FreeWindowStatus::class)]
        private FreeWindowStatus $status,
        #[ORM\Column(name: 'closed_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $closedAt,
        #[ORM\Column(name: 'closed_reason', length: 48, nullable: true)]
        private ?string $closedReason,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('Окончание свободного окна должно быть позже начала.');
        }
        $this->startsAt = $startsAt->setTimezone(new \DateTimeZone('UTC'));
        $this->endsAt = $endsAt->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function create(
        Organization $organization,
        Ulid $sourceAppointmentId,
        Ulid $specialistId,
        Ulid $serviceId,
        string $serviceName,
        int $durationMinutes,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self(new Ulid(), $organization, $sourceAppointmentId, $specialistId, $serviceId, $serviceName, $durationMinutes, $startsAt, $endsAt, FreeWindowStatus::Open, null, null, $createdAt->setTimezone(new \DateTimeZone('UTC')));
    }

    public function close(string $reason, \DateTimeImmutable $at): void
    {
        if (FreeWindowStatus::Closed === $this->status) {
            return;
        }
        $this->status = FreeWindowStatus::Closed;
        $this->closedAt = $at->setTimezone(new \DateTimeZone('UTC'));
        $this->closedReason = mb_substr(trim($reason), 0, 48);
    }

    public function reopen(): void
    {
        $this->status = FreeWindowStatus::Open;
        $this->closedAt = null;
        $this->closedReason = null;
    }

    public function id(): Ulid { return $this->id; }
    public function organization(): Organization { return $this->organization; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function sourceAppointmentId(): Ulid { return $this->sourceAppointmentId; }
    public function specialistId(): Ulid { return $this->specialistId; }
    public function serviceId(): Ulid { return $this->serviceId; }
    public function serviceName(): string { return $this->serviceName; }
    public function durationMinutes(): int { return $this->durationMinutes; }
    public function startsAt(): \DateTimeImmutable { return $this->startsAt; }
    public function endsAt(): \DateTimeImmutable { return $this->endsAt; }
    public function status(): FreeWindowStatus { return $this->status; }
}
