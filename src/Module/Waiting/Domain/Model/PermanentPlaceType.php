<?php

declare(strict_types=1);

namespace App\Module\Waiting\Domain\Model;

enum PermanentPlaceType: string
{
    case Single = 'SINGLE';
    case Bundle = 'BUNDLE';
}
