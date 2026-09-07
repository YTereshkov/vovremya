<?php

declare(strict_types=1);

namespace App\Module\Organization\Application;

use Symfony\Component\Uid\Ulid;

interface OrganizationExistenceChecker
{
    public function exists(Ulid $organizationId): bool;
}
