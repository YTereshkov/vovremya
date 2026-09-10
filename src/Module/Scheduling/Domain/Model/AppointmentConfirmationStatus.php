<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain\Model;

enum AppointmentConfirmationStatus: string
{
    case Pending = 'PENDING';
    case Confirmed = 'CONFIRMED';
    case CannotAttend = 'CANNOT_ATTEND';
    case NoResponse = 'NO_RESPONSE';
}
