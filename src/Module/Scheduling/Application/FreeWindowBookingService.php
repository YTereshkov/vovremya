<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Communications\Application\PendingAppointmentNotificationCancellation;
use App\Module\Organization\Domain\Model\Organization;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\AppointmentPlanningStatus;
use App\Module\Scheduling\Domain\Model\ScheduleAllocation;
use App\Module\Scheduling\Domain\TimeUnavailable;
use Symfony\Component\Uid\Ulid;

final readonly class FreeWindowBookingService
{
    public function __construct(
        private AppointmentStore $appointments,
        private ScheduleAllocationStore $allocations,
        private AvailabilityService $availability,
        private PendingAppointmentNotificationCancellation $notificationCancellation,
        private AppointmentHistoryRecorder $history,
    ) {
    }

    public function createOneOff(
        Organization $organization,
        Ulid $offerId,
        Ulid $specialistId,
        Ulid $clientId,
        Ulid $serviceId,
        string $serviceName,
        int $serviceDefaultDuration,
        ?int $serviceMinimumDuration,
        ?int $serviceMaximumDuration,
        int $durationMinutes,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $now,
    ): Appointment {
        $appointment = Appointment::create(
            $organization,
            $specialistId,
            $clientId,
            $serviceId,
            $serviceName,
            $serviceDefaultDuration,
            $serviceMinimumDuration,
            $serviceMaximumDuration,
            $durationMinutes,
            $startsAt,
        );
        $this->assertAvailable($specialistId, $appointment->startsAt(), $appointment->endsAt(), null, $offerId);
        $this->allocations->releaseForOffer($offerId);
        $this->allocations->save(ScheduleAllocation::forAppointment(
            $organization,
            $specialistId,
            $appointment->id(),
            $appointment->startsAt(),
            $appointment->endsAt(),
        ));
        $this->appointments->save($appointment);
        $this->history->record($appointment, 'CREATED_FROM_FREE_WINDOW_OFFER', ['offerId' => $offerId->toRfc4122()], null, $now);

        return $appointment;
    }

    public function moveEarlier(
        Ulid $offerId,
        Ulid $appointmentId,
        Ulid $expectedClientId,
        Ulid $expectedSpecialistId,
        Ulid $expectedServiceId,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $windowEndsAt,
        \DateTimeImmutable $now,
    ): FreeWindowMoveResult {
        $source = $this->appointments->lock($appointmentId) ?? throw new \DomainException('Занятие клиента больше недоступно для переноса.');
        if (AppointmentPlanningStatus::Planned !== $source->planningStatus()
            || null !== $source->resultStatus()
            || !$source->clientId()->equals($expectedClientId)
            || !$source->specialistId()->equals($expectedSpecialistId)
            || !$source->serviceId()->equals($expectedServiceId)
            || $source->startsAt() <= $startsAt
            || $source->startsAt() <= $now) {
            throw new \DomainException('Занятие клиента больше недоступно для переноса.');
        }
        $newEndsAt = $startsAt->modify(sprintf('+%d minutes', $source->durationMinutes()));
        if ($newEndsAt > $windowEndsAt) {
            throw new \DomainException('Занятие клиента больше не помещается в свободное окно.');
        }
        $this->assertAvailable($source->specialistId(), $startsAt, $newEndsAt, $source->id(), $offerId);
        $replacement = Appointment::rescheduledFrom($source, $startsAt);
        $this->allocations->releaseForAppointment($source->id());
        $this->allocations->releaseForOffer($offerId);
        $this->allocations->save(ScheduleAllocation::forAppointment(
            $source->organization(),
            $source->specialistId(),
            $replacement->id(),
            $replacement->startsAt(),
            $replacement->endsAt(),
        ));
        $this->appointments->save($replacement);
        $source->recordRescheduled($now);
        $this->notificationCancellation->cancel($source->id());
        $this->appointments->save($source);
        $payload = [
            'offerId' => $offerId->toRfc4122(),
            'from' => $source->startsAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
            'to' => $replacement->startsAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
            'newAppointmentId' => $replacement->id()->toRfc4122(),
        ];
        $this->history->record($source, 'APPOINTMENT_RESCHEDULED_FROM_FREE_WINDOW_OFFER', $payload, null, $now);
        $this->history->record($replacement, 'CREATED_FROM_FREE_WINDOW_OFFER', [
            'offerId' => $offerId->toRfc4122(),
            'fromAppointmentId' => $source->id()->toRfc4122(),
        ], null, $now);

        return new FreeWindowMoveResult($source, $replacement);
    }

    private function assertAvailable(Ulid $specialistId, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, ?Ulid $excludeAppointmentId, Ulid $offerId): void
    {
        $decision = $this->availability->check($specialistId, $startsAt, $endsAt, $excludeAppointmentId, $offerId);
        if (!$decision->available) {
            throw new TimeUnavailable($decision->conflict ?? throw new \LogicException('Missing availability conflict.'));
        }
    }
}
