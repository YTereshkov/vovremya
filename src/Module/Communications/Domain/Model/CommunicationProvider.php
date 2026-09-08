<?php

declare(strict_types=1);

namespace App\Module\Communications\Domain\Model;

enum CommunicationProvider: string
{
    case MAX = 'MAX';
    case TELEGRAM = 'TELEGRAM';
    case WHATSAPP = 'WHATSAPP';
}
