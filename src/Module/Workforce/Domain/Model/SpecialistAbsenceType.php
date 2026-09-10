<?php

declare(strict_types=1);

namespace App\Module\Workforce\Domain\Model;

enum SpecialistAbsenceType: string
{
    case Vacation = 'VACATION';
    case SickLeave = 'SICK_LEAVE';
    case Other = 'OTHER';
}
