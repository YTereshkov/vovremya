<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'regular_schedule_days')]
#[ORM\UniqueConstraint(name: 'uniq_regular_schedule_days_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\Index(name: 'idx_regular_schedule_days_active', columns: ['organization_id', 'regular_schedule_id', 'inactive_from'])]
final class RegularScheduleDay implements OrganizationOwned
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(name: 'regular_schedule_id', type: 'ulid')]
        private Ulid $regularScheduleId,
        #[ORM\Column(type: Types::SMALLINT)]
        private int $weekday,
        #[ORM\Column(name: 'start_time', length: 5)]
        private string $startTime,
        #[ORM\Column(name: 'duration_minutes', type: Types::SMALLINT)]
        private int $durationMinutes,
        #[ORM\Column(name: 'active_from', type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $activeFrom,
        #[ORM\Column(name: 'inactive_from', type: Types::DATE_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $inactiveFrom,
    ) {
    }

    public static function create(RegularSchedule $schedule, int $weekday, string $startTime, int $durationMinutes, \DateTimeImmutable $activeFrom): self
    {
        if (1 > $weekday || 7 < $weekday) {
            throw new \InvalidArgumentException('День недели должен быть от 1 до 7.');
        }
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $startTime)) {
            throw new \InvalidArgumentException('Укажите корректное время занятия.');
        }
        if (1 > $durationMinutes || 1440 < $durationMinutes
            || (null !== $schedule->serviceMinimumDuration() && $durationMinutes < $schedule->serviceMinimumDuration())
            || (null !== $schedule->serviceMaximumDuration() && $durationMinutes > $schedule->serviceMaximumDuration())) {
            throw new \InvalidArgumentException('Длительность занятия не входит в допустимый диапазон услуги.');
        }

        return new self(
            new Ulid(),
            $schedule->organization(),
            $schedule->id(),
            $weekday,
            $startTime,
            $durationMinutes,
            self::date($activeFrom),
            null,
        );
    }

    public function endFrom(\DateTimeImmutable $date): void
    {
        $date = self::date($date);
        if ($date < $this->activeFrom) {
            throw new \InvalidArgumentException('Изменение дня не может действовать раньше его начала.');
        }
        if (null === $this->inactiveFrom || $date < $this->inactiveFrom) {
            $this->inactiveFrom = $date;
        }
    }

    public function isActiveOn(\DateTimeImmutable $date): bool
    {
        $date = self::date($date);

        return $date >= $this->activeFrom && (null === $this->inactiveFrom || $date < $this->inactiveFrom);
    }

    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function regularScheduleId(): Ulid { return $this->regularScheduleId; }
    public function weekday(): int { return $this->weekday; }
    public function startTime(): string { return $this->startTime; }
    public function durationMinutes(): int { return $this->durationMinutes; }
    public function activeFrom(): \DateTimeImmutable { return $this->activeFrom; }
    public function inactiveFrom(): ?\DateTimeImmutable { return $this->inactiveFrom; }

    private static function date(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'));
    }
}
