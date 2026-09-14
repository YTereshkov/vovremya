<?php

declare(strict_types=1);

namespace App\Module\Waiting\Application;

use App\Module\Waiting\Domain\Model\PermanentPlace;
use App\Module\Waiting\Domain\Model\PermanentPlaceSlot;
use Symfony\Component\Uid\Ulid;

final readonly class PermanentPlaceManager
{
    public function __construct(
        private PermanentPlaceStore $places,
        private PermanentPlaceOfferStore $offers,
        private PermanentPlaceReservationService $reservations,
    ) {
    }

    /** @return list<array{place: PermanentPlace, slots: list<PermanentPlaceSlot>}> */
    public function openWithSlots(\DateTimeImmutable $now): array
    {
        $places = $this->places->open();
        $ids = array_map(static fn (PermanentPlace $place): Ulid => $place->id(), $places);
        $slots = $this->places->slotsForPlaces($ids);
        $offers = $this->offers->activeForPlaces($ids);

        return array_values(array_filter(array_map(function (PermanentPlace $place) use ($slots): array {
            return [
                'place' => $place,
                'slots' => $slots[$place->id()->toRfc4122()] ?? [],
            ];
        }, $places), fn (array $item): bool => isset($offers[$item['place']->id()->toRfc4122()])
            || $this->reservations->available($item['place'], $item['slots'], $now)));
    }
}
