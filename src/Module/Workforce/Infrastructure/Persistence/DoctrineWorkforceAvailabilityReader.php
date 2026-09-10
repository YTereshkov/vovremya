<?php

declare(strict_types=1);

namespace App\Module\Workforce\Infrastructure\Persistence;

use App\Module\Workforce\Application\SpecialistAvailability;
use App\Module\Workforce\Application\WorkforceAvailabilityReader;
use App\Module\Workforce\Application\WorkforceStore;
use App\Module\Workforce\Domain\Model\AdditionalWorkingDay;
use App\Module\Workforce\Domain\Model\SpecialistAbsence;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineWorkforceAvailabilityReader implements WorkforceAvailabilityReader
{
    public function __construct(private WorkforceStore $store)
    {
    }

    public function find(Ulid $specialistId): ?SpecialistAvailability
    {
        $specialist = $this->store->find($specialistId);
        if (null === $specialist) {
            return null;
        }

        $additionalDays = array_map(
            static fn (AdditionalWorkingDay $day): array => ['date' => $day->date(), 'work' => $day->work()],
            $this->store->additionalDays($specialistId),
        );
        $absences = array_map(
            static fn (SpecialistAbsence $absence): array => [
                'startsOn' => $absence->startsOn()->format('Y-m-d'),
                'endsOn' => $absence->endsOn()->format('Y-m-d'),
            ],
            $this->store->absences($specialistId),
        );

        return new SpecialistAvailability(
            $specialist->id(),
            $specialist->organization()->timezone(),
            $specialist->weeklyHours(),
            $additionalDays,
            $absences,
        );
    }
}
