<?php

declare(strict_types=1);

namespace App\Module\Waiting\Domain\Model;

enum FreeWindowOfferStatus: string
{
    case Active = 'ACTIVE';
    case Accepted = 'ACCEPTED';
    case Declined = 'DECLINED';
    case Cancelled = 'CANCELLED';
}
