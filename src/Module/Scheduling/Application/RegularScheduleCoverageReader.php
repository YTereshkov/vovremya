<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use Symfony\Component\Uid\Ulid;

interface RegularScheduleCoverageReader
{
    /** @param list<Ulid> $clientIds
     *  @return array<string, int>
     */
    public function weeklyFrequency(array $clientIds, Ulid $serviceId, \DateTimeImmutable $date): array;
}
