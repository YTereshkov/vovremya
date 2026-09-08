<?php

declare(strict_types=1);

namespace App\Module\Communications\Domain\Model;

enum WebhookInboxStatus: string
{
    case RECEIVED = 'RECEIVED';
    case PROCESSING = 'PROCESSING';
    case PROCESSED = 'PROCESSED';
    case FAILED = 'FAILED';
}
