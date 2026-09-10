<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\AvailabilityWarning;
use App\Module\Workforce\Application\SpecialistAvailability;
use App\Module\Workforce\Application\WorkforceAvailabilityReader;
use Symfony\Component\Uid\Ulid;

final readonly class SoftWarningService
{
    private const int RECOMMENDED_BREAK_MINUTES = 15;

    public function __construct(
        private WorkforceAvailabilityReader $workforce,
        private ScheduleAllocationStore $allocations,
    ) {
    }

    /** @return list<AvailabilityWarning> */
    public function check(Ulid $specialistId, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, ?Ulid $excludeAppointmentId = null): array
    {
        $schedule = $this->workforce->find($specialistId)
            ?? throw new \OutOfBoundsException('Специалист не найден.');
        $warnings = [];

        $lunch = $this->lunchOverlap($schedule, $startsAt, $endsAt);
        if (null !== $lunch) {
            $warnings[] = $lunch;
        }

        $shortBreak = $this->shortestBreak($specialistId, $startsAt, $endsAt, $excludeAppointmentId);
        if (null !== $shortBreak) {
            $warnings[] = $shortBreak;
        }

        return $warnings;
    }

    private function lunchOverlap(
        SpecialistAvailability $schedule,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
    ): ?AvailabilityWarning {
        $timezone = new \DateTimeZone($schedule->timezone);
        $localStart = $startsAt->setTimezone($timezone);
        $localEnd = $endsAt->setTimezone($timezone);
        $day = $schedule->weeklyHours[(int) $localStart->format('N') - 1] ?? null;

        if (!is_array($day) || true !== $day['enabled'] || !is_array($day['lunch'])) {
            return null;
        }

        $date = $localStart->format('Y-m-d');
        $lunchStart = new \DateTimeImmutable($date.' '.$day['lunch']['start'], $timezone);
        $lunchEnd = new \DateTimeImmutable($date.' '.$day['lunch']['end'], $timezone);

        return $localStart < $lunchEnd && $localEnd > $lunchStart
            ? AvailabilityWarning::lunch($day['lunch']['start'], $day['lunch']['end'])
            : null;
    }

    private function shortestBreak(
        Ulid $specialistId,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        ?Ulid $excludeAppointmentId,
    ): ?AvailabilityWarning {
        $shortest = null;

        foreach ($this->allocations->activeNear($specialistId, $startsAt, $endsAt, $excludeAppointmentId) as $interval) {
            if ($interval->endsAt <= $startsAt) {
                $minutes = (int) (($startsAt->getTimestamp() - $interval->endsAt->getTimestamp()) / 60);
                $candidate = AvailabilityWarning::shortBreak(
                    $minutes,
                    'PREVIOUS',
                    $interval->endsAt->format(\DateTimeInterface::RFC3339_EXTENDED),
                );
            } elseif ($interval->startsAt >= $endsAt) {
                $minutes = (int) (($interval->startsAt->getTimestamp() - $endsAt->getTimestamp()) / 60);
                $candidate = AvailabilityWarning::shortBreak(
                    $minutes,
                    'NEXT',
                    $interval->startsAt->format(\DateTimeInterface::RFC3339_EXTENDED),
                );
            } else {
                continue;
            }

            if ($minutes >= self::RECOMMENDED_BREAK_MINUTES) {
                continue;
            }
            if (null === $shortest || $minutes < $shortest['minutes']) {
                $shortest = ['minutes' => $minutes, 'warning' => $candidate];
            }
        }

        return $shortest['warning'] ?? null;
    }
}
