<?php

declare(strict_types=1);

namespace App\Module\Clients\Domain\Model;

enum ClientAbsenceMode: string
{
    case KeepPermanentPlace = 'KEEP_PERMANENT_PLACE';
    case ReleasePermanentPlace = 'RELEASE_PERMANENT_PLACE';
}
