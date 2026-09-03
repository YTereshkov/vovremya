<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Message;

use App\Shared\Infrastructure\Messenger\AsyncMessage;

final class InfrastructureHeartbeat implements AsyncMessage
{
    public const string CACHE_KEY = 'scheduler.last_heartbeat';
}
