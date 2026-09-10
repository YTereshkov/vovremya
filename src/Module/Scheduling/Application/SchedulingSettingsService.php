<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Domain\Model\Organization;
use App\Module\Scheduling\Domain\Model\SchedulingSettings;

final readonly class SchedulingSettingsService
{
    public function __construct(private SchedulingSettingsStore $store)
    {
    }

    public function forOrganization(Organization $organization): SchedulingSettings
    {
        return $this->store->current() ?? SchedulingSettings::defaults($organization);
    }

    public function change(AdministratorAccount $actor, int $lateCancellationHours): SchedulingSettings
    {
        $settings = $this->forOrganization($actor->organization());
        $settings->changeLateCancellationHours($lateCancellationHours);
        $this->store->save($settings);

        return $settings;
    }

    /** @return array{lateCancellationHours: int} */
    public function present(SchedulingSettings $settings): array
    {
        return ['lateCancellationHours' => $settings->lateCancellationHours()];
    }
}
