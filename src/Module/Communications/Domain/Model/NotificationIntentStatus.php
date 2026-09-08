<?php

declare(strict_types=1);

namespace App\Module\Communications\Domain\Model;

enum NotificationIntentStatus: string
{
    case PENDING = 'PENDING';
    case CANCELLED = 'CANCELLED';
}
