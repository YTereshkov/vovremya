<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\RegularSchedule;
use App\Module\Scheduling\Domain\Model\RegularScheduleDay;
use App\Module\Scheduling\Domain\Model\ScheduleAllocation;
use App\Module\Scheduling\Domain\Model\ScheduleGenerationIssue;
use App\Module\Scheduling\Domain\TimeUnavailable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RegularScheduleMaterializer
{
    public function __construct(
        private RegularScheduleStore $schedules,
        private AppointmentStore $appointments,
        private ScheduleAllocationStore $allocations,
        private AvailabilityService $availability,
        #[Autowire('%env(int:REGULAR_SCHEDULE_HORIZON_DAYS)%')]
        private int $horizonDays,
    ) {
        if (1 > $this->horizonDays) {
            throw new \InvalidArgumentException('REGULAR_SCHEDULE_HORIZON_DAYS must be positive.');
        }
    }

    /** @return list<RegularOccurrence> */
    public function occurrences(RegularSchedule $schedule, array $days, \DateTimeImmutable $from): array
    {
        $timezone = new \DateTimeZone($schedule->organization()->timezone());
        $cursor = new \DateTimeImmutable(max($from->format('Y-m-d'), $schedule->startsOn()->format('Y-m-d')), $timezone);
        $through = (new \DateTimeImmutable($from->format('Y-m-d'), $timezone))->modify(sprintf('+%d days', $this->horizonDays));
        $occurrences = [];
        while ($cursor <= $through) {
            if ($schedule->isActiveOn($cursor)) {
                foreach ($days as $day) {
                    if ($day instanceof RegularScheduleDay && $day->weekday() === (int) $cursor->format('N') && $day->isActiveOn($cursor)) {
                        $startsAt = new \DateTimeImmutable($cursor->format('Y-m-d').' '.$day->startTime(), $timezone);
                        $occurrences[] = new RegularOccurrence($day, $cursor, $startsAt, $startsAt->modify(sprintf('+%d minutes', $day->durationMinutes())));
                    }
                }
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $occurrences;
    }

    public function materialize(RegularSchedule $schedule, \DateTimeImmutable $from): void
    {
        $days = $this->schedules->days($schedule->id(), true);
        foreach ($this->occurrences($schedule, $days, $from) as $occurrence) {
            if (null !== $this->appointments->findRegularOccurrence($schedule->id(), $occurrence->date)) {
                $this->resolveIssue($schedule, $occurrence->date);
                continue;
            }
            $decision = $this->availability->check($schedule->specialistId(), $occurrence->startsAt, $occurrence->endsAt);
            if (!$decision->available) {
                $conflict = $decision->conflict ?? throw new \LogicException('Missing availability conflict.');
                $this->recordIssue($schedule, $occurrence->day, $occurrence->date, $conflict->code, $conflict->message);
                continue;
            }

            $appointment = Appointment::fromRegularSchedule($schedule, $occurrence->day, $occurrence->date, $occurrence->startsAt);
            try {
                $this->appointments->transactional(function () use ($schedule, $appointment): void {
                    $this->allocations->save(ScheduleAllocation::forAppointment(
                        $schedule->organization(),
                        $appointment->specialistId(),
                        $appointment->id(),
                        $appointment->startsAt(),
                        $appointment->endsAt(),
                    ));
                    $this->appointments->save($appointment);
                });
                $this->resolveIssue($schedule, $occurrence->date);
            } catch (TimeUnavailable $exception) {
                if (null !== $this->appointments->findRegularOccurrence($schedule->id(), $occurrence->date)) {
                    $this->resolveIssue($schedule, $occurrence->date);
                } else {
                    $this->recordIssue($schedule, $occurrence->day, $occurrence->date, $exception->conflict->code, $exception->conflict->message);
                }
            }
        }
    }

    private function recordIssue(RegularSchedule $schedule, RegularScheduleDay $day, \DateTimeImmutable $date, string $code, string $message): void
    {
        $issue = $this->schedules->findIssueForOccurrence($schedule->id(), $date);
        if (null === $issue) {
            $issue = ScheduleGenerationIssue::open($schedule, $day, $date, $code, $message);
        } else {
            $issue->refresh($day, $code, $message);
        }
        $this->schedules->save($issue);
    }

    private function resolveIssue(RegularSchedule $schedule, \DateTimeImmutable $date): void
    {
        $issue = $this->schedules->findIssueForOccurrence($schedule->id(), $date);
        if (null !== $issue && 'OPEN' === $issue->status()) {
            $issue->resolve();
            $this->schedules->save($issue);
        }
    }
}
