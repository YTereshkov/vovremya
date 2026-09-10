<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use Symfony\Component\Uid\Ulid;

final readonly class CalendarAppointment
{
    public function __construct(
        public Ulid $id,
        public Ulid $specialistId,
        public string $specialistName,
        public string $specialistSpecialization,
        public Ulid $clientId,
        public string $clientName,
        public Ulid $serviceId,
        public string $serviceName,
        public int $serviceDefaultDuration,
        public ?int $serviceMinimumDuration,
        public ?int $serviceMaximumDuration,
        public int $durationMinutes,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public string $confirmationStatus,
        public ?string $resultStatus,
        public ?\DateTimeImmutable $resultRecordedAt,
        public ?bool $lateCancellation,
        public bool $respectfulReason,
        public ?string $resultComment,
    ) {
    }
}
