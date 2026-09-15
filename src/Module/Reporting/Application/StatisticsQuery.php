<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use Symfony\Component\Uid\Ulid;

interface StatisticsQuery
{
    /** @return array<string, mixed> */
    public function summary(?string $month, ?Ulid $specialistId): array;
}
