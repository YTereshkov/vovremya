<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\Model\TransferOption;
use App\Module\Scheduling\Domain\Model\TransferRequest;
use Symfony\Component\Uid\Ulid;

interface TransferStore
{
    public function find(Ulid $id): ?TransferRequest;

    public function findActiveForAppointment(Ulid $appointmentId): ?TransferRequest;

    public function lock(Ulid $id): ?TransferRequest;

    public function findOption(Ulid $id): ?TransferOption;

    /** @return list<TransferOption> */
    public function options(Ulid $requestId): array;

    public function save(TransferRequest|TransferOption ...$entities): void;

    public function transactional(callable $operation): mixed;
}
