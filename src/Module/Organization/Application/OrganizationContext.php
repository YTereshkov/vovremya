<?php

declare(strict_types=1);

namespace App\Module\Organization\Application;

use Symfony\Component\Uid\Ulid;

interface OrganizationContext
{
    public function currentId(): Ulid;

    public function currentIdOrNull(): ?Ulid;

    public function runWith(Ulid $organizationId, callable $operation): mixed;
}
