<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\AppointmentEvent;
use Symfony\Component\Uid\Ulid;

interface AppointmentStore
{
    public function find(Ulid $id): ?Appointment;

    public function findRegularOccurrence(Ulid $scheduleId, \DateTimeImmutable $date): ?Appointment;

    /** @return list<Appointment> */
    public function futureRegular(Ulid $scheduleId, \DateTimeImmutable $from, ?Ulid $dayId = null): array;

    /** @return list<Appointment> */
    public function plannedForSpecialistBetween(Ulid $specialistId, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): array;

    /** @return list<Appointment> */
    public function plannedForClientBetween(Ulid $clientId, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, bool $oneOffOnly = false): array;

    public function save(Appointment|AppointmentEvent $entity): void;

    /** @return list<AppointmentEvent> */
    public function history(Ulid $appointmentId): array;

    public function transactional(callable $operation): mixed;
}
