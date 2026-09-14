<?php

declare(strict_types=1);

namespace App\Module\Waiting\Application;

use App\Module\Waiting\Domain\Model\PermanentPlace;
use App\Module\Waiting\Domain\Model\PermanentPlaceSlot;
use Symfony\Component\Uid\Ulid;

interface PermanentPlaceStore
{
    public function find(Ulid $id): ?PermanentPlace;
    public function lock(Ulid $id): ?PermanentPlace;
    public function findBySourceSchedule(Ulid $scheduleId): ?PermanentPlace;
    public function findBySourceDay(Ulid $dayId): ?PermanentPlace;

    /** @return list<PermanentPlace> */
    public function open(): array;

    /** @return list<PermanentPlaceSlot> */
    public function slots(Ulid $placeId): array;

    /** @param list<Ulid> $placeIds
     *  @return array<string, list<PermanentPlaceSlot>>
     */
    public function slotsForPlaces(array $placeIds): array;

    public function save(PermanentPlace|PermanentPlaceSlot ...$entities): void;
    public function transactional(callable $operation): mixed;
}
