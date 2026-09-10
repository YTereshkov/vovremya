<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\AppointmentConfirmationAction;
use App\Module\Scheduling\Domain\Model\AppointmentConfirmationRequest;
use Symfony\Component\Uid\Ulid;

interface AppointmentConfirmationStore
{
    public function findAppointment(Ulid $id): ?Appointment;

    public function findByAppointment(Ulid $appointmentId): ?AppointmentConfirmationRequest;

    /** @return list<Appointment> */
    public function futureAppointments(\DateTimeImmutable $now, \DateTimeImmutable $until): array;

    public function save(AppointmentConfirmationRequest|AppointmentConfirmationAction $entity): void;

    public function consumeAction(Ulid $actionId, string $token, Ulid $channelConnectionId, \DateTimeImmutable $now): ?ConsumedConfirmationAction;

    /** @return list<array{appointmentId: string, clientName: string, startsAt: string}> */
    public function noResponseAttention(\DateTimeImmutable $now): array;

    public function transactional(callable $operation): mixed;
}
