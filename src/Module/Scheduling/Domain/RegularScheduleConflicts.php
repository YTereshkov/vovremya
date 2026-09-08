<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain;

use App\Module\Scheduling\Application\RegularScheduleConflict;

final class RegularScheduleConflicts extends \DomainException
{
    /** @param list<RegularScheduleConflict> $conflicts */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct('Некоторые занятия регулярного расписания нельзя создать.');
    }
}
