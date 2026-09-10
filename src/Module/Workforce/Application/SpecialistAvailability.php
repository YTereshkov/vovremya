<?php

declare(strict_types=1);

namespace App\Module\Workforce\Application;

use Symfony\Component\Uid\Ulid;

final readonly class SpecialistAvailability
{
    /**
     * @param array<int, array{weekday: int, enabled: bool, work: array{start: string, end: string}|null, lunch: array{start: string, end: string}|null}> $weeklyHours
     * @param list<array{date: string, work: array{start: string, end: string}}> $additionalDays
     * @param list<array{startsOn: string, endsOn: string}> $absences
     */
    public function __construct(
        public Ulid $specialistId,
        public string $timezone,
        public array $weeklyHours,
        public array $additionalDays,
        public array $absences,
    ) {
    }
}
