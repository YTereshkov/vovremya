<?php

declare(strict_types=1);

namespace App\Module\Waiting\Application;

use App\Module\Waiting\Domain\Model\FreeWindow;
use Symfony\Component\Uid\Ulid;

interface FreeWindowStore
{
    public function find(Ulid $id): ?FreeWindow;

    public function lock(Ulid $id): ?FreeWindow;

    public function findBySourceAppointment(Ulid $appointmentId): ?FreeWindow;

    /** @return list<FreeWindow> */
    public function openFuture(\DateTimeImmutable $now): array;

    public function save(FreeWindow $window): void;
}
