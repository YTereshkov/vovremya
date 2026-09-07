<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

final readonly class AllocationInterval
{
    public function __construct(
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
    ) {
    }
}
