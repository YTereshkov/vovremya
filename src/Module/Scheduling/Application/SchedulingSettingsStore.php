<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\Model\SchedulingSettings;

interface SchedulingSettingsStore
{
    public function current(): ?SchedulingSettings;

    public function save(SchedulingSettings $settings): void;
}
