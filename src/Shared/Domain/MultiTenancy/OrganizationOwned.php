<?php

declare(strict_types=1);

namespace App\Shared\Domain\MultiTenancy;

use Symfony\Component\Uid\Ulid;

interface OrganizationOwned
{
    public function organizationId(): Ulid;
}
