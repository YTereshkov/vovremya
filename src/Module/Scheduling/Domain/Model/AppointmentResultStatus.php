<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain\Model;

enum AppointmentResultStatus: string
{
    case Conducted = 'CONDUCTED';
    case CancelledByClient = 'CANCELLED_BY_CLIENT';
    case CancelledBySpecialist = 'CANCELLED_BY_SPECIALIST';
    case NoShow = 'NO_SHOW';
    case Rescheduled = 'RESCHEDULED';
}
