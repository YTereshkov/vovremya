<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use Symfony\Component\Uid\Ulid;

final readonly class EarlierAppointmentCandidate
{
    public function __construct(
        public Ulid $appointmentId,
        public Ulid $clientId,
        public \DateTimeImmutable $currentStartsAt,
        public int $durationMinutes,
    ) {
    }
}
