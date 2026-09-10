<?php

declare(strict_types=1);

namespace App\Module\Workforce\Application;

use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Scheduling\Application\AppointmentAbsenceService;
use App\Module\Workforce\Domain\Model\Specialist;
use App\Module\Workforce\Domain\Model\SpecialistAbsence;
use App\Module\Workforce\Domain\Model\SpecialistAbsenceType;

final readonly class SpecialistAbsenceService
{
    public function __construct(private WorkforceStore $store, private AppointmentAbsenceService $appointments)
    {
    }

    public function impact(Specialist $specialist, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn): int
    {
        return $this->appointments->specialistImpact($specialist->organization(), $specialist->id(), $startsOn, $endsOn);
    }

    /** @return array{absence: SpecialistAbsence, appointments: int, notifications: int} */
    public function create(
        AdministratorAccount $actor,
        Specialist $specialist,
        SpecialistAbsenceType $type,
        \DateTimeImmutable $startsOn,
        \DateTimeImmutable $endsOn,
        ?string $comment,
        bool $notifyClients,
        \DateTimeImmutable $now,
    ): array {
        $absence = SpecialistAbsence::create($specialist, $type, $startsOn, $endsOn, $comment, $notifyClients, $now);

        return $this->store->transactional(function () use ($actor, $absence, $specialist, $type, $startsOn, $endsOn, $comment, $notifyClients, $now): array {
            $this->store->save($absence);
            $result = $this->appointments->cancelForSpecialistAbsence(
                $actor,
                $absence->id(),
                $specialist->id(),
                $type->value,
                $startsOn,
                $endsOn,
                $comment,
                $notifyClients,
                $now,
            );

            return ['absence' => $absence, ...$result];
        });
    }
}
