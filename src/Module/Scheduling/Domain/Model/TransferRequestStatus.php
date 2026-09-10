<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain\Model;

enum TransferRequestStatus: string
{
    case AwaitingOptions = 'AWAITING_OPTIONS';
    case OptionsSent = 'OPTIONS_SENT';
    case Completed = 'COMPLETED';
    case Declined = 'DECLINED';
    case Cancelled = 'CANCELLED';
}
