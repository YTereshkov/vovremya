<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain\Model;

enum ScheduleAllocationType: string
{
    case Appointment = 'APPOINTMENT';
    case OfferReservation = 'OFFER_RESERVATION';
}
