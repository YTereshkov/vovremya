<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Communications\Application\PendingAppointmentNotificationCancellation;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\AppointmentPlanningStatus;
use App\Module\Scheduling\Domain\Model\AppointmentResultStatus;
use App\Module\Waiting\Application\FreeWindowManager;
use Symfony\Component\Uid\Ulid;

final readonly class AppointmentLifecycleService
{
    public function __construct(
        private AppointmentStore $appointments,
        private ScheduleAllocationStore $allocations,
        private SchedulingSettingsService $settings,
        private AppointmentHistoryRecorder $history,
        private FreeWindowManager $freeWindows,
        private PendingAppointmentNotificationCancellation $notificationCancellation,
        private TransferService $transfers,
    ) {
    }

    /** @return array{appointment: Appointment, freeWindowCreated: bool} */
    public function recordResult(
        AdministratorAccount $actor,
        Ulid $appointmentId,
        AppointmentResultStatus $status,
        bool $respectfulReason,
        ?string $comment,
        bool $createFreeWindow,
        \DateTimeImmutable $now,
    ): array {
        if ($createFreeWindow && AppointmentResultStatus::CancelledByClient !== $status) {
            throw new \InvalidArgumentException('Свободное окно создаётся только при отмене клиентом.');
        }
        $lateThreshold = $this->settings->forOrganization($actor->organization())->lateCancellationHours();

        return $this->appointments->transactional(function () use ($actor, $appointmentId, $status, $respectfulReason, $comment, $createFreeWindow, $now, $lateThreshold): array {
            $appointment = $this->appointments->lock($appointmentId)
                ?? throw new \OutOfBoundsException('Занятие не найдено.');
            if (AppointmentPlanningStatus::Planned !== $appointment->planningStatus()) {
                throw new \OutOfBoundsException('Занятие не найдено.');
            }
            if (AppointmentResultStatus::Rescheduled === $appointment->resultStatus()) {
                throw new \DomainException('Результат перенесённого занятия нельзя изменить.');
            }
            if ($createFreeWindow && $appointment->startsAt() <= $now) {
                throw new \DomainException('Свободное окно можно создать только для будущего занятия.');
            }
            $windowWasOpen = $this->freeWindows->isOpenForAppointment($appointment->id());
            if ($appointment->hasResult($status, $respectfulReason, $comment) && $windowWasOpen === $createFreeWindow) {
                return ['appointment' => $appointment, 'freeWindowCreated' => false];
            }
            $previousStatus = $appointment->resultStatus()?->value;
            $this->transfers->cancelActiveForAppointment($appointment->id(), $actor->id(), 'APPOINTMENT_RESULT_CHANGED', $now);
            $appointment->recordResult($status, $now, $lateThreshold, $respectfulReason, $comment);
            $this->notificationCancellation->cancel($appointment->id());
            if (in_array($status, [AppointmentResultStatus::CancelledByClient, AppointmentResultStatus::CancelledBySpecialist], true)) {
                $this->allocations->releaseForAppointment($appointment->id());
            } elseif (in_array($status, [AppointmentResultStatus::Conducted, AppointmentResultStatus::NoShow], true)) {
                $this->allocations->restoreForAppointment($appointment->id());
            }

            $windowCreated = false;
            if ($createFreeWindow) {
                $this->freeWindows->createFromCancellation(
                    $appointment->organization(),
                    $appointment->id(),
                    $appointment->specialistId(),
                    $appointment->serviceId(),
                    $appointment->serviceName(),
                    $appointment->durationMinutes(),
                    $appointment->startsAt(),
                    $appointment->endsAt(),
                    $now,
                );
                $windowCreated = true;
            } else {
                $this->freeWindows->closeForAppointment($appointment->id(), 'RESULT_CHANGED', $now);
            }

            $this->appointments->save($appointment);
            $this->history->record($appointment, 'RESULT_CHANGED', [
                'from' => $previousStatus,
                'to' => $status->value,
                'lateCancellation' => $appointment->resultIsLate(),
                'respectfulReason' => $appointment->resultRespectfulReason(),
                'comment' => $appointment->resultComment(),
                'freeWindowOpen' => $createFreeWindow,
                'lateCancellationHours' => $lateThreshold,
            ], $actor->id(), $now);

            return ['appointment' => $appointment, 'freeWindowCreated' => $windowCreated];
        });
    }

    /** @return array{status: ?string, recordedAt: ?string, lateCancellation: ?bool, respectfulReason: bool, comment: ?string, freeWindowOpen: bool} */
    public function result(Ulid $appointmentId): array
    {
        $appointment = $this->appointments->find($appointmentId)
            ?? throw new \OutOfBoundsException('Занятие не найдено.');

        return [
            'status' => $appointment->resultStatus()?->value,
            'recordedAt' => $appointment->resultRecordedAt()?->format(\DateTimeInterface::RFC3339_EXTENDED),
            'lateCancellation' => $appointment->resultIsLate(),
            'respectfulReason' => $appointment->resultRespectfulReason(),
            'comment' => $appointment->resultComment(),
            'freeWindowOpen' => $this->freeWindows->isOpenForAppointment($appointmentId),
        ];
    }
}
