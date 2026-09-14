<?php

declare(strict_types=1);

namespace App\Module\Waiting\Application;

use App\Module\Scheduling\Application\AvailabilityService;
use App\Module\Scheduling\Application\ScheduleAllocationStore;
use App\Module\Scheduling\Domain\Model\ScheduleAllocation;
use App\Module\Scheduling\Domain\TimeUnavailable;
use App\Module\Waiting\Domain\Model\PermanentPlace;
use App\Module\Waiting\Domain\Model\PermanentPlaceSlot;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Ulid;

final readonly class PermanentPlaceReservationService
{
    public function __construct(
        private AvailabilityService $availability,
        private ScheduleAllocationStore $allocations,
        #[Autowire('%env(int:REGULAR_SCHEDULE_HORIZON_DAYS)%')]
        private int $horizonDays,
    ) {
        if (1 > $this->horizonDays) {
            throw new \InvalidArgumentException('REGULAR_SCHEDULE_HORIZON_DAYS must be positive.');
        }
    }

    /** @param list<PermanentPlaceSlot> $slots */
    public function available(PermanentPlace $place, array $slots, \DateTimeImmutable $now, ?Ulid $excludeOfferId = null): bool
    {
        foreach ($this->occurrences($place, $slots, $now) as $occurrence) {
            if (!$this->availability->check($place->specialistId(), $occurrence['startsAt'], $occurrence['endsAt'], null, $excludeOfferId)->available) {
                return false;
            }
        }

        return true;
    }

    /** @param list<PermanentPlaceSlot> $slots */
    public function reserve(PermanentPlace $place, array $slots, Ulid $offerId, \DateTimeImmutable $now): void
    {
        $existing = [];
        foreach ($this->allocations->activeForOffer($offerId) as $allocation) {
            $existing[$this->key($allocation['startsAt'], $allocation['endsAt'])] = true;
        }
        foreach ($this->occurrences($place, $slots, $now) as $occurrence) {
            $decision = $this->availability->check($place->specialistId(), $occurrence['startsAt'], $occurrence['endsAt'], null, $offerId);
            if (!$decision->available) {
                throw new TimeUnavailable($decision->conflict ?? throw new \LogicException('Missing availability conflict.'));
            }
            $key = $this->key($occurrence['startsAt'], $occurrence['endsAt']);
            if (isset($existing[$key])) {
                continue;
            }
            $this->allocations->save(ScheduleAllocation::forOfferReservation(
                $place->organization(),
                $place->specialistId(),
                $offerId,
                $occurrence['startsAt'],
                $occurrence['endsAt'],
            ));
        }
    }

    /** @param list<PermanentPlaceSlot> $slots
     *  @return list<array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}>
     */
    public function occurrences(PermanentPlace $place, array $slots, \DateTimeImmutable $now): array
    {
        $timezone = new \DateTimeZone($place->organization()->timezone());
        $today = new \DateTimeImmutable($now->setTimezone($timezone)->format('Y-m-d'), $timezone);
        $from = $place->availableFrom()->format('Y-m-d') > $today->format('Y-m-d')
            ? new \DateTimeImmutable($place->availableFrom()->format('Y-m-d'), $timezone)
            : $today;
        $through = $today->modify(sprintf('+%d days', max(7, $this->horizonDays)));
        $result = [];
        for ($date = $from; $date <= $through; $date = $date->modify('+1 day')) {
            foreach ($slots as $slot) {
                if ($slot->weekday() !== (int) $date->format('N')) {
                    continue;
                }
                $startsAt = new \DateTimeImmutable($date->format('Y-m-d').' '.$slot->startTime(), $timezone);
                if ($startsAt <= $now) {
                    continue;
                }
                $result[] = ['startsAt' => $startsAt, 'endsAt' => $startsAt->modify(sprintf('+%d minutes', $slot->durationMinutes()))];
            }
        }

        return $result;
    }

    private function key(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): string
    {
        return $startsAt->format('U.u').'|'.$endsAt->format('U.u');
    }
}
