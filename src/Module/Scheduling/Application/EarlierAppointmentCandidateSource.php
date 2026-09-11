<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use Symfony\Component\Uid\Ulid;

interface EarlierAppointmentCandidateSource
{
    /** @return list<EarlierAppointmentCandidate> */
    public function laterAppointments(Ulid $specialistId, Ulid $serviceId, \DateTimeImmutable $after, \DateTimeImmutable $before): array;
}
