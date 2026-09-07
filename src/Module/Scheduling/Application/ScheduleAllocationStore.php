<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\Model\ScheduleAllocation;
use Symfony\Component\Uid\Ulid;

interface ScheduleAllocationStore
{
    public function hasActiveConflict(Ulid $specialistId, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): bool;

    public function save(ScheduleAllocation $allocation): void;
}
