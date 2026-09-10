<?php

declare(strict_types=1);

namespace App\Module\Organization\Application;

use App\Module\Organization\Domain\Model\Organization;
use Symfony\Component\Uid\Ulid;

interface OrganizationDirectory
{
    /** @return list<Ulid> */
    public function allIds(): array;

    public function save(Organization $organization): void;
}
