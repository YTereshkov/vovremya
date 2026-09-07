<?php

declare(strict_types=1);

namespace App\Module\Catalog\Application;

use App\Module\Catalog\Domain\Model\Service;
use Symfony\Component\Uid\Ulid;

interface ServiceStore
{
    /** @return list<Service> */
    public function active(): array;

    public function findActive(Ulid $id): ?Service;

    public function save(Service $service): void;
}
