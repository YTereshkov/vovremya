<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Identity\Domain\Model\AdministratorAccount;
use Symfony\Component\Uid\Ulid;

interface AdministratorAccountReader
{
    public function findInCurrentOrganization(Ulid $administratorId): ?AdministratorAccount;
}
