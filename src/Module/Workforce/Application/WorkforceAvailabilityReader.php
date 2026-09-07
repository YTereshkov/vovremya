<?php

declare(strict_types=1);

namespace App\Module\Workforce\Application;

use Symfony\Component\Uid\Ulid;

interface WorkforceAvailabilityReader
{
    public function find(Ulid $specialistId): ?SpecialistAvailability;
}
