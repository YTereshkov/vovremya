<?php

declare(strict_types=1);

namespace App\Module\Workforce\Application;

use App\Module\Workforce\Domain\Model\AdditionalWorkingDay;
use App\Module\Workforce\Domain\Model\Specialist;
use Symfony\Component\Uid\Ulid;

interface WorkforceStore
{
    /** @return list<Specialist> */
    public function all(): array;
    public function find(Ulid $id): ?Specialist;
    /** @return list<AdditionalWorkingDay> */
    public function additionalDays(?Ulid $specialistId = null): array;
    public function save(Specialist|AdditionalWorkingDay $entity): void;
    public function remove(Specialist|AdditionalWorkingDay $entity): void;
}
