<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\Model\Appointment;

final readonly class FreeWindowMoveResult
{
    public function __construct(public Appointment $source, public Appointment $replacement)
    {
    }
}
