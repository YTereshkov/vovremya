<?php

declare(strict_types=1);

namespace App\Module\Organization\Application;

use Symfony\Component\Uid\Ulid;

interface OrganizationAwareMessage
{
    public function organizationId(): Ulid;
}
