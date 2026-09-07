<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'schedule_allocations')]
#[ORM\UniqueConstraint(name: 'uniq_schedule_allocations_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\Index(name: 'idx_schedule_allocations_source', columns: ['organization_id', 'allocation_type', 'source_id'])]
final class ScheduleAllocation implements OrganizationOwned
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(name: 'specialist_id', type: 'ulid')]
        private Ulid $specialistId,
        #[ORM\Column(name: 'source_id', type: 'ulid')]
        private Ulid $sourceId,
        #[ORM\Column(name: 'allocation_type', length: 24, enumType: ScheduleAllocationType::class)]
        private ScheduleAllocationType $type,
        #[ORM\Column(name: 'starts_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $startsAt,
        #[ORM\Column(name: 'ends_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $endsAt,
        #[ORM\Column(name: 'released_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $releasedAt,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('Окончание интервала должно быть позже начала.');
        }

        $utc = new \DateTimeZone('UTC');
        $this->startsAt = $startsAt->setTimezone($utc);
        $this->endsAt = $endsAt->setTimezone($utc);
    }

    public static function forAppointment(
        Organization $organization,
        Ulid $specialistId,
        Ulid $appointmentId,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
    ): self {
        return self::create($organization, $specialistId, $appointmentId, ScheduleAllocationType::Appointment, $startsAt, $endsAt);
    }

    public static function forOfferReservation(
        Organization $organization,
        Ulid $specialistId,
        Ulid $offerId,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
    ): self {
        return self::create($organization, $specialistId, $offerId, ScheduleAllocationType::OfferReservation, $startsAt, $endsAt);
    }

    private static function create(
        Organization $organization,
        Ulid $specialistId,
        Ulid $sourceId,
        ScheduleAllocationType $type,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
    ): self {
        return new self(
            new Ulid(),
            $organization,
            $specialistId,
            $sourceId,
            $type,
            $startsAt,
            $endsAt,
            null,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    public function release(?\DateTimeImmutable $releasedAt = null): void
    {
        $this->releasedAt ??= ($releasedAt ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'));
    }

    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function specialistId(): Ulid { return $this->specialistId; }
    public function sourceId(): Ulid { return $this->sourceId; }
    public function type(): ScheduleAllocationType { return $this->type; }
    public function startsAt(): \DateTimeImmutable { return $this->startsAt; }
    public function endsAt(): \DateTimeImmutable { return $this->endsAt; }
    public function releasedAt(): ?\DateTimeImmutable { return $this->releasedAt; }
}
