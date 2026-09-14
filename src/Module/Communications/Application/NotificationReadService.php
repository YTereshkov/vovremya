<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

use App\Module\Identity\Domain\Model\AdministratorAccount;

final readonly class NotificationReadService
{
    public function __construct(private NotificationReadStateStore $states)
    {
    }

    public function markAllRead(AdministratorAccount $administrator, \DateTimeImmutable $at): \DateTimeImmutable
    {
        return $this->states->markReadThrough($administrator, $at);
    }
}
