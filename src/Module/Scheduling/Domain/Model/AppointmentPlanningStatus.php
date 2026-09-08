<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain\Model;

enum AppointmentPlanningStatus: string
{
    case Planned = 'PLANNED';
    case RemovedFromSchedule = 'REMOVED_FROM_SCHEDULE';
}
