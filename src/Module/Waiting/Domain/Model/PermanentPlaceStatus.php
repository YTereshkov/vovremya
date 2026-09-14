<?php

declare(strict_types=1);

namespace App\Module\Waiting\Domain\Model;

enum PermanentPlaceStatus: string
{
    case Open = 'OPEN';
    case Claimed = 'CLAIMED';
    case Closed = 'CLOSED';
}
