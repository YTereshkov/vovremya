<?php

declare(strict_types=1);

namespace App\Module\Waiting\Domain\Model;

enum FreeWindowOfferTargetType: string
{
    case MoveEarlier = 'MOVE_EARLIER';
    case WaitingList = 'WAITING_LIST';
}
