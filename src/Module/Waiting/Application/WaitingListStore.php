<?php

declare(strict_types=1);

namespace App\Module\Waiting\Application;

use App\Module\Waiting\Domain\Model\WaitingListAvailability;
use App\Module\Waiting\Domain\Model\WaitingListEntry;
use Symfony\Component\Uid\Ulid;

interface WaitingListStore
{
    public function find(Ulid $id): ?WaitingListEntry;

    public function findActiveForClient(Ulid $clientId): ?WaitingListEntry;

    /** @return list<WaitingListAvailability> */
    public function availability(Ulid $entryId): array;

    /** @return list<WaitingListEntry> */
    public function matchingOneOff(Ulid $serviceId, Ulid $specialistId, \DateTimeImmutable $date, int $weekday, string $startTime, string $endTime): array;

    public function save(WaitingListEntry|WaitingListAvailability ...$entities): void;

    public function replaceAvailability(WaitingListEntry $entry, WaitingListAvailability ...$availability): void;

    public function transactional(callable $operation): mixed;
}
