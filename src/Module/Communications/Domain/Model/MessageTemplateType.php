<?php

declare(strict_types=1);

namespace App\Module\Communications\Domain\Model;

enum MessageTemplateType: string
{
    case CONFIRMATION = 'CONFIRMATION';
    case TRANSFER = 'TRANSFER';
    case FREE_WINDOW = 'FREE_WINDOW';
}
