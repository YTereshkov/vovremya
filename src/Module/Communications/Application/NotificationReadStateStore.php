<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

use App\Module\Identity\Domain\Model\AdministratorAccount;

interface NotificationReadStateStore
{
    public function markReadThrough(AdministratorAccount $administrator, \DateTimeImmutable $at): \DateTimeImmutable;
}
