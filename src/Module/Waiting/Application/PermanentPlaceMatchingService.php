<?php

declare(strict_types=1);

namespace App\Module\Waiting\Application;

use App\Module\Catalog\Application\ServiceCatalog;
use App\Module\Clients\Application\WaitingClientReader;
use App\Module\Scheduling\Application\RegularScheduleCoverageReader;
use App\Module\Waiting\Domain\Model\PermanentPlace;
use App\Module\Waiting\Domain\Model\PermanentPlaceSlot;
use App\Module\Waiting\Domain\Model\PermanentPlaceStatus;
use App\Module\Waiting\Domain\Model\WaitingListAvailability;
use App\Module\Waiting\Domain\Model\WaitingListEntry;
use Symfony\Component\Uid\Ulid;

final readonly class PermanentPlaceMatchingService
{
    public function __construct(
        private PermanentPlaceStore $places,
        private WaitingListStore $waiting,
        private WaitingClientReader $clients,
        private RegularScheduleCoverageReader $coverage,
        private ServiceCatalog $services,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function candidates(Ulid $placeId): array
    {
        $place = $this->places->find($placeId) ?? throw new \OutOfBoundsException('Постоянное место не найдено.');
        if (PermanentPlaceStatus::Open !== $place->status()) {
            throw new \OutOfBoundsException('Постоянное место не найдено.');
        }
        try {
            $this->services->find($place->serviceId()->toRfc4122());
        } catch (\OutOfBoundsException) {
            return [];
        }
        $slots = $this->places->slots($place->id());
        $entries = $this->waiting->matchingPermanent($place->serviceId(), $place->specialistId(), $place->availableFrom());
        $availability = $this->waiting->availabilityForEntries(array_map(static fn (WaitingListEntry $entry): Ulid => $entry->id(), $entries));
        $profiles = $this->clients->availableProfiles(array_map(static fn (WaitingListEntry $entry): Ulid => $entry->clientId(), $entries), $place->availableFrom());
        $frequency = $this->coverage->weeklyFrequency(array_map(static fn (WaitingListEntry $entry): Ulid => $entry->clientId(), $entries), $place->serviceId(), $place->availableFrom());
        $result = [];
        foreach ($entries as $entry) {
            $clientKey = $entry->clientId()->toRfc4122();
            $current = $frequency[$clientKey] ?? 0;
            $remaining = max(0, $entry->requiredFrequency() - $current);
            $entryAvailability = $availability[$entry->id()->toRfc4122()] ?? [];
            if (!isset($profiles[$clientKey]) || count($slots) > $remaining || !$this->covers($entryAvailability, $slots)) {
                continue;
            }
            $result[] = [
                'waitingListEntryId' => $entry->id()->toRfc4122(),
                'client' => ['id' => $clientKey, 'name' => $profiles[$clientKey]->name],
                'requiredFrequency' => $entry->requiredFrequency(),
                'currentFrequency' => $current,
                'remainingFrequency' => $remaining,
                'availability' => $this->availabilityLabel($entryAvailability),
            ];
        }

        return $result;
    }

    /** @param list<WaitingListAvailability> $availability
     *  @param list<PermanentPlaceSlot> $slots
     */
    private function covers(array $availability, array $slots): bool
    {
        foreach ($slots as $slot) {
            $slotEnd = (new \DateTimeImmutable('2000-01-01 '.$slot->startTime(), new \DateTimeZone('UTC')))
                ->modify(sprintf('+%d minutes', $slot->durationMinutes()));
            if ('2000-01-01' !== $slotEnd->format('Y-m-d')) {
                return false;
            }
            $covered = false;
            foreach ($availability as $range) {
                if ($range->weekday() === $slot->weekday()
                    && $range->startTime() <= $slot->startTime()
                    && (null === $range->endTime() || $range->endTime() >= $slotEnd->format('H:i'))) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                return false;
            }
        }

        return true;
    }

    /** @param list<WaitingListAvailability> $availability */
    private function availabilityLabel(array $availability): string
    {
        $labels = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];

        return implode(' · ', array_map(static fn (WaitingListAvailability $day): string => sprintf(
            '%s %s',
            $labels[$day->weekday()],
            null === $day->endTime() ? 'после '.$day->startTime() : $day->startTime().'–'.$day->endTime(),
        ), $availability));
    }
}
