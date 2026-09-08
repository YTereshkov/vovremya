<?php

declare(strict_types=1);

namespace App\Module\Communications\Application\Message;

use App\Shared\Infrastructure\Messenger\AsyncMessage;

final readonly class PublishPendingOutboundMessages implements AsyncMessage
{
}
