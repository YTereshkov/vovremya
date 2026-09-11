<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\Model\ScheduleAllocation;
use Symfony\Component\Uid\Ulid;

interface ScheduleAllocationStore
{
    public function hasActiveConflict(Ulid $specialistId, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, ?Ulid $excludeAppointmentId = null, ?Ulid $excludeOfferId = null): bool;

    /** @return list<AllocationInterval> */
    public function activeNear(Ulid $specialistId, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, ?Ulid $excludeAppointmentId = null, ?Ulid $excludeOfferId = null): array;

    public function save(ScheduleAllocation $allocation): void;

    public function releaseForAppointment(Ulid $appointmentId): void;

    public function releaseForOffer(Ulid $offerId): void;

    public function restoreForAppointment(Ulid $appointmentId): void;
}
