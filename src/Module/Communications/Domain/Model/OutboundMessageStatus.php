<?php

declare(strict_types=1);

namespace App\Module\Communications\Domain\Model;

enum OutboundMessageStatus: string
{
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case SENT = 'SENT';
    case FAILED = 'FAILED';
}
