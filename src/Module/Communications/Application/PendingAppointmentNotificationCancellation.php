<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

use Symfony\Component\Uid\Ulid;

interface PendingAppointmentNotificationCancellation
{
    public function cancel(Ulid $appointmentId): void;
}
