<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain\Model;

enum ConfirmationActionType: string
{
    case Confirm = 'CONFIRM';
    case CannotAttend = 'CANNOT_ATTEND';
}
