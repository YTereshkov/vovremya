<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\Model\RegularSchedule;
use App\Module\Scheduling\Domain\Model\RegularScheduleDay;
use App\Module\Scheduling\Domain\Model\AppointmentPlanningStatus;
use App\Module\Waiting\Application\PermanentPlaceRegistrar;
use Symfony\Component\Uid\Ulid;

final readonly class RegularScheduleManager
{
    public function __construct(
        private RegularScheduleStore $schedules,
        private AppointmentStore $appointments,
        private ScheduleAllocationStore $allocations,
        private RegularScheduleMaterializer $materializer,
        private TransferService $transfers,
        private PermanentPlaceRegistrar $permanentPlaces,
    ) {
    }

    public function end(string $scheduleId, \DateTimeImmutable $from): RegularSchedule
    {
        $schedule = $this->schedule($scheduleId);
        $days = $this->activeDays($schedule, $from);
        $this->schedules->transactional(function () use ($schedule, $days, $from): void {
            $schedule->endFrom($from);
            $this->removeMaterialized($schedule, $from);
            $this->resolveObsoleteIssues($schedule, $from);
            $this->schedules->save($schedule);
            $this->permanentPlaces->register($schedule, $days, $from, new \DateTimeImmutable());
        });

        return $schedule;
    }

    public function endDay(string $scheduleId, string $dayId, \DateTimeImmutable $from): RegularScheduleDay
    {
        $schedule = $this->schedule($scheduleId);
        $day = $this->day($schedule, $dayId);
        $release = $schedule->isActiveOn($from) && $day->isActiveOn($from);
        $this->schedules->transactional(function () use ($schedule, $day, $from, $release): void {
            $day->endFrom($from);
            $this->removeMaterialized($schedule, $from, $day->id());
            $this->resolveObsoleteIssues($schedule, $from, $day->id());
            $this->schedules->save($day);
            if ($release) {
                $this->permanentPlaces->register($schedule, [$day], $from, new \DateTimeImmutable());
            }
        });

        return $day;
    }

    public function endForClient(Ulid $clientId, \DateTimeImmutable $from): int
    {
        $schedules = $this->schedules->activeForClient($clientId, $from);
        $this->schedules->transactional(function () use ($schedules, $from): void {
            foreach ($schedules as $schedule) {
                $days = $this->activeDays($schedule, $from);
                $schedule->endFrom($from);
                $this->removeMaterialized($schedule, $from);
                $this->resolveObsoleteIssues($schedule, $from);
                $this->schedules->save($schedule);
                $this->permanentPlaces->register($schedule, $days, $from, new \DateTimeImmutable());
            }
        });

        return count($schedules);
    }

    public function replaceDay(string $scheduleId, string $dayId, \DateTimeImmutable $from, string $startTime, int $durationMinutes): RegularScheduleDay
    {
        $schedule = $this->schedule($scheduleId);
        $oldDay = $this->day($schedule, $dayId);
        $newDay = RegularScheduleDay::create($schedule, $oldDay->weekday(), $startTime, $durationMinutes, $from);
        $this->schedules->transactional(function () use ($schedule, $oldDay, $newDay, $from): void {
            $oldDay->endFrom($from);
            $this->removeMaterialized($schedule, $from, $oldDay->id());
            $this->resolveObsoleteIssues($schedule, $from, $oldDay->id());
            $this->schedules->save($oldDay, $newDay);
        });
        $this->materializer->materialize($schedule, $from);

        return $newDay;
    }

    public function retryIssue(string $issueId): void
    {
        $issue = $this->schedules->findIssue($this->id($issueId)) ?? throw new \OutOfBoundsException('Проблема генерации не найдена.');
        $schedule = $this->schedules->find($issue->regularScheduleId()) ?? throw new \OutOfBoundsException('Регулярное расписание не найдено.');
        $this->materializer->materialize($schedule, $issue->occurrenceDate());
    }

    public function materializeAll(\DateTimeImmutable $from): void
    {
        foreach ($this->schedules->all() as $schedule) {
            $timezone = new \DateTimeZone($schedule->organization()->timezone());
            $localFrom = new \DateTimeImmutable($from->setTimezone($timezone)->format('Y-m-d'), $timezone);
            if (null === $schedule->inactiveFrom() || $schedule->inactiveFrom() > $localFrom) {
                $this->materializer->materialize($schedule, $localFrom);
            }
        }
    }

    private function removeMaterialized(RegularSchedule $schedule, \DateTimeImmutable $from, ?Ulid $dayId = null): void
    {
        $timezone = new \DateTimeZone($schedule->organization()->timezone());
        $instant = new \DateTimeImmutable($from->format('Y-m-d').' 00:00', $timezone);
        foreach ($this->appointments->futureRegular($schedule->id(), $instant, $dayId) as $appointment) {
            $appointment = $this->appointments->lock($appointment->id());
            if (null === $appointment || AppointmentPlanningStatus::Planned !== $appointment->planningStatus()) {
                continue;
            }
            $this->transfers->cancelActiveForAppointment($appointment->id(), null, 'REGULAR_SCHEDULE_CHANGED', new \DateTimeImmutable());
            $appointment->removeFromSchedule();
            $this->allocations->releaseForAppointment($appointment->id());
            $this->appointments->save($appointment);
        }
    }

    private function resolveObsoleteIssues(RegularSchedule $schedule, \DateTimeImmutable $from, ?Ulid $dayId = null): void
    {
        foreach ($this->schedules->openIssues($schedule->id()) as $issue) {
            if ($issue->occurrenceDate() >= $from && (null === $dayId || $issue->regularScheduleDayId()->equals($dayId))) {
                $issue->resolve();
                $this->schedules->save($issue);
            }
        }
    }

    /** @return list<RegularScheduleDay> */
    private function activeDays(RegularSchedule $schedule, \DateTimeImmutable $from): array
    {
        if (!$schedule->isActiveOn($from)) {
            return [];
        }

        return array_values(array_filter(
            $this->schedules->days($schedule->id(), true),
            static fn (RegularScheduleDay $day): bool => $day->isActiveOn($from),
        ));
    }

    private function schedule(string $id): RegularSchedule
    {
        return $this->schedules->find($this->id($id)) ?? throw new \OutOfBoundsException('Регулярное расписание не найдено.');
    }

    private function day(RegularSchedule $schedule, string $id): RegularScheduleDay
    {
        return $this->schedules->findDay($schedule->id(), $this->id($id)) ?? throw new \OutOfBoundsException('День регулярного расписания не найден.');
    }

    private function id(string $value): Ulid
    {
        try {
            return Ulid::fromString($value);
        } catch (\InvalidArgumentException) {
            throw new \OutOfBoundsException('Объект не найден.');
        }
    }
}
