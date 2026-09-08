<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application\Message;

use App\Shared\Infrastructure\Messenger\AsyncMessage;

final readonly class MaterializeRegularSchedules implements AsyncMessage
{
}
