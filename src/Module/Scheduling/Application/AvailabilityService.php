<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\AvailabilityConflict;
use App\Module\Workforce\Application\SpecialistAvailability;
use App\Module\Workforce\Application\WorkforceAvailabilityReader;
use Symfony\Component\Uid\Ulid;

final readonly class AvailabilityService
{
    public function __construct(
        private WorkforceAvailabilityReader $workforce,
        private ScheduleAllocationStore $allocations,
    ) {
    }

    public function check(Ulid $specialistId, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): AvailabilityDecision
    {
        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('Окончание интервала должно быть позже начала.');
        }

        $schedule = $this->workforce->find($specialistId)
            ?? throw new \OutOfBoundsException('Специалист не найден.');

        if ($this->overlapsAbsence($schedule, $startsAt, $endsAt)) {
            return AvailabilityDecision::unavailable(AvailabilityConflict::specialistAbsent());
        }

        if (!$this->isInsideWorkingHours($schedule, $startsAt, $endsAt)) {
            return AvailabilityDecision::unavailable(AvailabilityConflict::specialistNotWorking());
        }

        if ($this->allocations->hasActiveConflict($specialistId, $startsAt, $endsAt)) {
            return AvailabilityDecision::unavailable(AvailabilityConflict::timeAlreadyUnavailable());
        }

        return AvailabilityDecision::available();
    }

    private function overlapsAbsence(SpecialistAvailability $schedule, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): bool
    {
        $timezone = new \DateTimeZone($schedule->timezone);
        $firstDate = $startsAt->setTimezone($timezone)->format('Y-m-d');
        $lastDate = $endsAt->modify('-1 microsecond')->setTimezone($timezone)->format('Y-m-d');
        foreach ($schedule->absences as $absence) {
            if ($absence['startsOn'] <= $lastDate && $absence['endsOn'] >= $firstDate) {
                return true;
            }
        }

        return false;
    }

    private function isInsideWorkingHours(
        SpecialistAvailability $schedule,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
    ): bool {
        $timezone = new \DateTimeZone($schedule->timezone);
        $localStart = $startsAt->setTimezone($timezone);
        $localEnd = $endsAt->setTimezone($timezone);
        $date = $localStart->format('Y-m-d');

        if ($date !== $localEnd->format('Y-m-d')) {
            return false;
        }

        $intervals = [];
        $weekday = (int) $localStart->format('N');
        $weeklyDay = $schedule->weeklyHours[$weekday - 1] ?? null;
        if (is_array($weeklyDay) && true === $weeklyDay['enabled'] && is_array($weeklyDay['work'])) {
            $intervals[] = $weeklyDay['work'];
        }
        foreach ($schedule->additionalDays as $additionalDay) {
            if ($additionalDay['date'] === $date) {
                $intervals[] = $additionalDay['work'];
            }
        }

        foreach ($intervals as $interval) {
            $workStart = new \DateTimeImmutable($date.' '.$interval['start'], $timezone);
            $workEnd = new \DateTimeImmutable($date.' '.$interval['end'], $timezone);
            if ($localStart >= $workStart && $localEnd <= $workEnd) {
                return true;
            }
        }

        return false;
    }
}
