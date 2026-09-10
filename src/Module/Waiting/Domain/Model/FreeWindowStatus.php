<?php

declare(strict_types=1);

namespace App\Module\Waiting\Domain\Model;

enum FreeWindowStatus: string
{
    case Open = 'OPEN';
    case Closed = 'CLOSED';
}
