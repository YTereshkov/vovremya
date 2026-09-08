<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use Symfony\Component\Uid\Ulid;

interface CalendarQuery
{
    /** @return list<CalendarAppointment> */
    public function between(\DateTimeImmutable $from, \DateTimeImmutable $until, ?Ulid $specialistId = null): array;

    public function find(Ulid $appointmentId): ?CalendarAppointment;
}
