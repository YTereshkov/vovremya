<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Catalog\Application\ServiceStore;
use App\Module\Clients\Application\ClientStore;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\AppointmentEvent;
use App\Module\Scheduling\Domain\Model\ScheduleAllocation;
use App\Module\Scheduling\Domain\SoftWarningsRequired;
use App\Module\Scheduling\Domain\TimeUnavailable;
use App\Module\Workforce\Application\WorkforceStore;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use Symfony\Component\Uid\Ulid;

final readonly class AppointmentCreator
{
    public function __construct(
        private AppointmentStore $appointments,
        private ScheduleAllocationStore $allocations,
        private AvailabilityService $availability,
        private SoftWarningService $warnings,
        private WorkforceStore $workforce,
        private ClientStore $clients,
        private ServiceStore $services,
    ) {
    }

    /** @param list<string> $acceptedWarnings */
    public function create(
        AdministratorAccount $actor,
        string $specialistId,
        string $clientId,
        string $serviceId,
        string $date,
        string $startTime,
        ?int $durationMinutes,
        array $acceptedWarnings,
    ): Appointment {
        $specialist = $this->workforce->find($this->id($specialistId, 'Специалист не найден.'))
            ?? throw new \OutOfBoundsException('Специалист не найден.');
        $client = $this->clients->find($this->id($clientId, 'Клиент не найден.'))
            ?? throw new \OutOfBoundsException('Клиент не найден.');
        $service = $this->services->findActive($this->id($serviceId, 'Услуга не найдена.'))
            ?? throw new \OutOfBoundsException('Услуга не найдена.');
        if (!OrganizationIsolation::belongsTo($actor->organizationId(), $specialist, $client, $service)) {
            throw new \LogicException('Cannot create an appointment across organizations.');
        }
        $duration = $durationMinutes ?? $service->defaultDurationMinutes();
        $startsAt = $this->localInstant($date, $startTime, $actor->organization()->timezone());
        $appointment = Appointment::create(
            $actor->organization(),
            $specialist->id(),
            $client->id(),
            $service->id(),
            $service->name(),
            $service->defaultDurationMinutes(),
            $service->minimumDurationMinutes(),
            $service->maximumDurationMinutes(),
            $duration,
            $startsAt,
        );
        $decision = $this->availability->check($specialist->id(), $startsAt, $appointment->endsAt());
        if (!$decision->available) {
            throw new TimeUnavailable($decision->conflict ?? throw new \LogicException('Missing availability conflict.'));
        }

        $warnings = $this->warnings->check($specialist->id(), $startsAt, $appointment->endsAt());
        $accepted = array_fill_keys($acceptedWarnings, true);
        $missing = array_filter($warnings, static fn ($warning): bool => !isset($accepted[$warning->code]));
        if ([] !== $missing) {
            throw new SoftWarningsRequired(array_values($warnings));
        }

        return $this->appointments->transactional(function () use ($actor, $appointment, $warnings): Appointment {
            $this->allocations->save(ScheduleAllocation::forAppointment(
                $actor->organization(),
                $appointment->specialistId(),
                $appointment->id(),
                $appointment->startsAt(),
                $appointment->endsAt(),
            ));
            $this->appointments->save($appointment);
            if ([] !== $warnings) {
                $this->appointments->save(AppointmentEvent::softWarningsAccepted(
                    $appointment,
                    $actor->organization(),
                    $actor->id(),
                    array_map(static fn ($warning): array => $warning->toArray(), $warnings),
                ));
            }

            return $appointment;
        });
    }

    private function id(string $value, string $message): Ulid
    {
        try {
            return Ulid::fromString($value);
        } catch (\InvalidArgumentException) {
            throw new \OutOfBoundsException($message);
        }
    }

    private function localInstant(string $date, string $time, string $timezone): \DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || !preg_match('/^\d{2}:\d{2}$/D', $time)) {
            throw new \InvalidArgumentException('Укажите корректные дату и время.');
        }

        $instant = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$time, new \DateTimeZone($timezone));
        if (false === $instant || $instant->format('Y-m-d H:i') !== $date.' '.$time) {
            throw new \InvalidArgumentException('Укажите корректные дату и время.');
        }

        return $instant;
    }
}
