<?php

declare(strict_types=1);

namespace App\Module\Waiting\Application;

enum PermanentPlaceOfferResponse: string
{
    case Accepted = 'ACCEPTED';
    case Unavailable = 'UNAVAILABLE';
    case Ignored = 'IGNORED';
}
