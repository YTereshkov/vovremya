<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\AppointmentEvent;

interface AppointmentStore
{
    public function save(Appointment|AppointmentEvent $entity): void;

    public function transactional(callable $operation): mixed;
}
