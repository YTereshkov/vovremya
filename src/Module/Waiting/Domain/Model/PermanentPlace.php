<?php

declare(strict_types=1);

namespace App\Module\Waiting\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Module\Scheduling\Domain\Model\RegularSchedule;
use App\Module\Scheduling\Domain\Model\RegularScheduleDay;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'permanent_places')]
#[ORM\UniqueConstraint(name: 'uniq_permanent_places_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\Index(name: 'idx_permanent_places_open', columns: ['organization_id', 'status', 'available_from', 'created_at'])]
final class PermanentPlace implements OrganizationOwned
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(name: 'source_type', length: 12, enumType: PermanentPlaceType::class)]
        private PermanentPlaceType $type,
        #[ORM\Column(name: 'source_regular_schedule_id', type: 'ulid')]
        private Ulid $sourceRegularScheduleId,
        #[ORM\Column(name: 'source_regular_schedule_day_id', type: 'ulid', nullable: true)]
        private ?Ulid $sourceRegularScheduleDayId,
        #[ORM\Column(name: 'specialist_id', type: 'ulid')]
        private Ulid $specialistId,
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
        #[ORM\Column(name: 'available_from', type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $availableFrom,
        #[ORM\Column(length: 12, enumType: PermanentPlaceStatus::class)]
        private PermanentPlaceStatus $status,
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

    /** @param list<RegularScheduleDay> $days */
    public static function create(RegularSchedule $schedule, array $days, \DateTimeImmutable $availableFrom, \DateTimeImmutable $now): self
    {
        if ([] === $days) {
            throw new \InvalidArgumentException('Постоянное место должно содержать хотя бы один день.');
        }
        foreach ($days as $day) {
            if (!$day->organizationId()->equals($schedule->organizationId()) || !$day->regularScheduleId()->equals($schedule->id())) {
                throw new \LogicException('Cannot create a permanent place from another schedule.');
            }
        }
        $type = 1 === count($days) ? PermanentPlaceType::Single : PermanentPlaceType::Bundle;

        return new self(
            new Ulid(),
            $schedule->organization(),
            $type,
            $schedule->id(),
            PermanentPlaceType::Single === $type ? $days[0]->id() : null,
            $schedule->specialistId(),
            $schedule->serviceId(),
            $schedule->serviceName(),
            $schedule->serviceDefaultDuration(),
            $schedule->serviceMinimumDuration(),
            $schedule->serviceMaximumDuration(),
            self::date($availableFrom),
            PermanentPlaceStatus::Open,
            null,
            null,
            $now->setTimezone(new \DateTimeZone('UTC')),
            null,
        );
    }

    public function claim(Ulid $regularScheduleId, \DateTimeImmutable $now): void
    {
        if (PermanentPlaceStatus::Open !== $this->status) {
            throw new \DomainException('Постоянное место больше недоступно.');
        }
        $this->status = PermanentPlaceStatus::Claimed;
        $this->resultingRegularScheduleId = $regularScheduleId;
        $this->closedReason = 'OFFER_ACCEPTED';
        $this->closedAt = $now->setTimezone(new \DateTimeZone('UTC'));
    }

    public function id(): Ulid { return $this->id; }
    public function organization(): Organization { return $this->organization; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function type(): PermanentPlaceType { return $this->type; }
    public function sourceRegularScheduleId(): Ulid { return $this->sourceRegularScheduleId; }
    public function sourceRegularScheduleDayId(): ?Ulid { return $this->sourceRegularScheduleDayId; }
    public function specialistId(): Ulid { return $this->specialistId; }
    public function serviceId(): Ulid { return $this->serviceId; }
    public function serviceName(): string { return $this->serviceName; }
    public function serviceDefaultDuration(): int { return $this->serviceDefaultDuration; }
    public function serviceMinimumDuration(): ?int { return $this->serviceMinimumDuration; }
    public function serviceMaximumDuration(): ?int { return $this->serviceMaximumDuration; }
    public function availableFrom(): \DateTimeImmutable { return $this->availableFrom; }
    public function status(): PermanentPlaceStatus { return $this->status; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }

    private static function date(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'));
    }
}
