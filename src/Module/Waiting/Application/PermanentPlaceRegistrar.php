<?php

declare(strict_types=1);

namespace App\Module\Waiting\Application;

use App\Module\Scheduling\Domain\Model\RegularSchedule;
use App\Module\Scheduling\Domain\Model\RegularScheduleDay;
use App\Module\Waiting\Domain\Model\PermanentPlace;
use App\Module\Waiting\Domain\Model\PermanentPlaceSlot;

final readonly class PermanentPlaceRegistrar
{
    public function __construct(private PermanentPlaceStore $places)
    {
    }

    /** @param list<RegularScheduleDay> $days */
    public function register(RegularSchedule $schedule, array $days, \DateTimeImmutable $availableFrom, \DateTimeImmutable $now): ?PermanentPlace
    {
        if ([] === $days) {
            return null;
        }
        $existing = 1 === count($days)
            ? $this->places->findBySourceDay($days[0]->id())
            : $this->places->findBySourceSchedule($schedule->id());
        if (null !== $existing) {
            return $existing;
        }
        $place = PermanentPlace::create($schedule, $days, $availableFrom, $now);
        $slots = array_map(static fn (RegularScheduleDay $day): PermanentPlaceSlot => PermanentPlaceSlot::create($place, $day), $days);
        $this->places->save($place, ...$slots);

        return $place;
    }
}
