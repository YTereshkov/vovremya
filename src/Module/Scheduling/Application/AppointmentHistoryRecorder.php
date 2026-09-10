<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\AppointmentEvent;
use Symfony\Component\Uid\Ulid;

final readonly class AppointmentHistoryRecorder
{
    public function __construct(private AppointmentStore $appointments)
    {
    }

    /** @param array<string, mixed> $payload */
    public function record(Appointment $appointment, string $type, array $payload, ?Ulid $actorId, \DateTimeImmutable $at): void
    {
        $this->appointments->save(AppointmentEvent::record($appointment, $actorId, $type, $payload, $at));
    }

    /** @param array<string, mixed> $payload */
    public function recordByAppointmentId(Ulid $appointmentId, string $type, array $payload, ?Ulid $actorId, \DateTimeImmutable $at): void
    {
        $appointment = $this->appointments->find($appointmentId);
        if (null === $appointment) {
            throw new \OutOfBoundsException('Занятие не найдено.');
        }

        $this->record($appointment, $type, $payload, $actorId, $at);
    }

    /** @return list<array{id: string, type: string, payload: array<string, mixed>, actorAdministratorId: ?string, occurredAt: string}> */
    public function history(Ulid $appointmentId): array
    {
        if (null === $this->appointments->find($appointmentId)) {
            throw new \OutOfBoundsException('Занятие не найдено.');
        }

        return array_map(static fn (AppointmentEvent $event): array => [
            'id' => $event->id()->toRfc4122(),
            'type' => $event->type(),
            'payload' => $event->payload(),
            'actorAdministratorId' => $event->actorAdministratorId()?->toRfc4122(),
            'occurredAt' => $event->occurredAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
        ], $this->appointments->history($appointmentId));
    }
}
