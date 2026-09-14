<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use Symfony\Component\Uid\Ulid;

interface NotificationCenterQuery
{
    /** @return array{items: list<array<string, mixed>>, unreadCount: int} */
    public function list(Ulid $administratorId): array;

    /** @return array{absenceId: ?string, items: list<array<string, mixed>>} */
    public function deliveryReport(?Ulid $absenceId): array;
}
