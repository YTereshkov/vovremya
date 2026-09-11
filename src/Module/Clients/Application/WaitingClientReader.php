<?php

declare(strict_types=1);

namespace App\Module\Clients\Application;

use Symfony\Component\Uid\Ulid;

interface WaitingClientReader
{
    /** @param list<Ulid> $clientIds
     *  @return array<string, WaitingClientProfile>
     */
    public function availableProfiles(array $clientIds, \DateTimeImmutable $localDate): array;
}
