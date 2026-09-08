<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\Model\RegularScheduleDay;

final readonly class RegularOccurrence
{
    public function __construct(
        public RegularScheduleDay $day,
        public \DateTimeImmutable $date,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
    ) {
    }
}
