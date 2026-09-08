<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\AppointmentEvent;
use Symfony\Component\Uid\Ulid;

interface AppointmentStore
{
    public function findRegularOccurrence(Ulid $scheduleId, \DateTimeImmutable $date): ?Appointment;

    /** @return list<Appointment> */
    public function futureRegular(Ulid $scheduleId, \DateTimeImmutable $from, ?Ulid $dayId = null): array;

    public function save(Appointment|AppointmentEvent $entity): void;

    public function transactional(callable $operation): mixed;
}
