<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

use App\Module\Communications\Domain\Model\ConfirmationSettings;

interface ConfirmationSettingsStore
{
    public function current(): ?ConfirmationSettings;

    public function save(ConfirmationSettings $settings): void;
}
