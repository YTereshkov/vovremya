<?php

declare(strict_types=1);

namespace App\Module\Waiting\Application;

use App\Module\Waiting\Domain\Model\PermanentPlaceOffer;
use Symfony\Component\Uid\Ulid;

interface PermanentPlaceOfferStore
{
    public function find(Ulid $id): ?PermanentPlaceOffer;
    public function lock(Ulid $id): ?PermanentPlaceOffer;
    public function activeForPlace(Ulid $placeId): ?PermanentPlaceOffer;

    /** @param list<Ulid> $placeIds
     *  @return array<string, PermanentPlaceOffer>
     */
    public function activeForPlaces(array $placeIds): array;

    /** @return list<PermanentPlaceOffer> */
    public function active(): array;

    public function save(PermanentPlaceOffer $offer): void;
    public function transactional(callable $operation): mixed;
}
