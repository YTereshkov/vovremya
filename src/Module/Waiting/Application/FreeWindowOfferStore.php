<?php

declare(strict_types=1);

namespace App\Module\Waiting\Application;

use App\Module\Waiting\Domain\Model\FreeWindowOffer;
use Symfony\Component\Uid\Ulid;

interface FreeWindowOfferStore
{
    public function find(Ulid $id): ?FreeWindowOffer;

    public function lock(Ulid $id): ?FreeWindowOffer;

    public function activeForWindow(Ulid $windowId): ?FreeWindowOffer;

    /** @param list<Ulid> $windowIds
     *  @return array<string, FreeWindowOffer>
     */
    public function activeForWindows(array $windowIds): array;

    public function save(FreeWindowOffer $offer): void;

    public function transactional(callable $operation): mixed;
}
