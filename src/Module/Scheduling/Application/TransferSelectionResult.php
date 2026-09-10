<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

enum TransferSelectionResult: string
{
    case Completed = 'COMPLETED';
    case Unavailable = 'UNAVAILABLE';
    case Ignored = 'IGNORED';
}
