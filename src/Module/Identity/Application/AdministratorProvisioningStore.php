<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Domain\Model\Organization;

interface AdministratorProvisioningStore
{
    public function emailExists(string $normalizedEmail): bool;

    public function save(Organization $organization, AdministratorAccount $administrator): void;
}
