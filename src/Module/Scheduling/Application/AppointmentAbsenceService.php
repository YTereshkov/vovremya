<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Clients\Application\NotificationRecipientResolver;
use App\Module\Communications\Application\NotificationOutbox;
use App\Module\Communications\Application\PendingAppointmentNotificationCancellation;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Domain\Model\Organization;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\AppointmentResultStatus;
use App\Module\Waiting\Application\FreeWindowManager;
use Symfony\Component\Uid\Ulid;

final readonly class AppointmentAbsenceService
{
    public function __construct(
        private AppointmentStore $appointments,
        private ScheduleAllocationStore $allocations,
        private SchedulingSettingsService $settings,
        private AppointmentHistoryRecorder $history,
        private FreeWindowManager $freeWindows,
        private PendingAppointmentNotificationCancellation $notificationCancellation,
        private NotificationRecipientResolver $recipients,
        private NotificationOutbox $outbox,
        private TransferService $transfers,
    ) {
    }

    public function specialistImpact(Organization $organization, Ulid $specialistId, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn): int
    {
        [$startsAt, $endsAt] = $this->bounds($organization, $startsOn, $endsOn);

        return count($this->appointments->plannedForSpecialistBetween($specialistId, $startsAt, $endsAt));
    }

    /** @return array{appointments: int, notifications: int} */
    public function cancelForSpecialistAbsence(
        AdministratorAccount $actor,
        Ulid $absenceId,
        Ulid $specialistId,
        string $absenceType,
        \DateTimeImmutable $startsOn,
        \DateTimeImmutable $endsOn,
        ?string $comment,
        bool $notifyClients,
        \DateTimeImmutable $now,
    ): array {
        [$startsAt, $endsAt] = $this->bounds($actor->organization(), $startsOn, $endsOn);

        return $this->appointments->transactional(function () use ($actor, $absenceId, $specialistId, $absenceType, $startsOn, $endsOn, $startsAt, $endsAt, $comment, $notifyClients, $now): array {
            $appointments = $this->appointments->lockPlannedForSpecialistBetween($specialistId, $startsAt, $endsAt);
            $clientIds = [];
            foreach ($appointments as $appointment) {
                $this->transfers->cancelActiveForAppointment($appointment->id(), $actor->id(), 'SPECIALIST_ABSENCE', $now);
                $appointment->recordResult(AppointmentResultStatus::CancelledBySpecialist, $now, 1, false, $comment);
                $this->releaseAndRecord($appointment, $actor->id(), 'SPECIALIST_ABSENCE_CANCELLED', [
                    'absenceId' => $absenceId->toRfc4122(),
                    'absenceType' => $absenceType,
                ], $now, false);
                $clientIds[$appointment->clientId()->toRfc4122()] = $appointment->clientId();
            }

            $notifications = 0;
            if ($notifyClients) {
                foreach ($clientIds as $clientId) {
                    $notifications += $this->notify(
                        $clientId,
                        'SPECIALIST_ABSENCE',
                        sprintf('Специалист отсутствует с %s по %s. Занятия в этот период отменены.', $startsOn->format('d.m.Y'), $endsOn->format('d.m.Y')),
                        'specialist-absence:'.$absenceId->toRfc4122().':'.$clientId->toRfc4122(),
                        ['absenceId' => $absenceId->toRfc4122()],
                    );
                }
            }

            return ['appointments' => count($appointments), 'notifications' => $notifications];
        });
    }

    public function clientImpact(Organization $organization, Ulid $clientId, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn): int
    {
        [$startsAt, $endsAt] = $this->bounds($organization, $startsOn, $endsOn);

        return count($this->appointments->plannedForClientBetween($clientId, $startsAt, $endsAt));
    }

    /** @return array{appointments: int, freeWindows: int, notifications: int} */
    public function cancelForClientAbsence(
        AdministratorAccount $actor,
        Ulid $absenceId,
        Ulid $clientId,
        \DateTimeImmutable $startsOn,
        \DateTimeImmutable $endsOn,
        ?string $reason,
        bool $createFreeWindows,
        bool $notifyClient,
        bool $oneOffOnly,
        \DateTimeImmutable $now,
    ): array {
        [$startsAt, $endsAt] = $this->bounds($actor->organization(), $startsOn, $endsOn);
        $lateThreshold = $this->settings->forOrganization($actor->organization())->lateCancellationHours();

        return $this->appointments->transactional(function () use ($actor, $absenceId, $clientId, $startsOn, $endsOn, $startsAt, $endsAt, $reason, $createFreeWindows, $notifyClient, $oneOffOnly, $lateThreshold, $now): array {
            $appointments = $this->appointments->lockPlannedForClientBetween($clientId, $startsAt, $endsAt, $oneOffOnly);
            $freeWindows = 0;
            foreach ($appointments as $appointment) {
                $this->transfers->cancelActiveForAppointment($appointment->id(), $actor->id(), 'CLIENT_ABSENCE', $now);
                $appointment->recordResult(AppointmentResultStatus::CancelledByClient, $now, $lateThreshold, false, $reason);
                $openWindow = $createFreeWindows && $appointment->startsAt() > $now;
                $this->releaseAndRecord($appointment, $actor->id(), 'CLIENT_ABSENCE_CANCELLED', [
                    'absenceId' => $absenceId->toRfc4122(),
                    'freeWindowOpen' => $openWindow,
                ], $now, $openWindow);
                $freeWindows += (int) $openWindow;
            }

            $notifications = $notifyClient ? $this->notify(
                $clientId,
                'CLIENT_ABSENCE',
                sprintf('Отсутствие с %s по %s сохранено. Занятия в этот период отменены.', $startsOn->format('d.m.Y'), $endsOn->format('d.m.Y')),
                'client-absence:'.$absenceId->toRfc4122(),
                ['absenceId' => $absenceId->toRfc4122()],
            ) : 0;

            return ['appointments' => count($appointments), 'freeWindows' => $freeWindows, 'notifications' => $notifications];
        });
    }

    /** @param array<string, mixed> $payload */
    private function releaseAndRecord(Appointment $appointment, Ulid $actorId, string $eventType, array $payload, \DateTimeImmutable $now, bool $openWindow): void
    {
        $this->notificationCancellation->cancel($appointment->id());
        $this->allocations->releaseForAppointment($appointment->id());
        if ($openWindow) {
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
        } else {
            $this->freeWindows->closeForAppointment($appointment->id(), 'ABSENCE_CHANGED', $now);
        }
        $this->appointments->save($appointment);
        $this->history->record($appointment, $eventType, $payload, $actorId, $now);
    }

    /** @param array<string, mixed> $payload */
    private function notify(Ulid $clientId, string $type, string $body, string $dedupeKey, array $payload): int
    {
        $recipient = $this->recipients->primaryForClient($clientId);
        if (null === $recipient) {
            return 0;
        }
        $provider = CommunicationProvider::tryFrom($recipient->provider);
        if (null === $provider) {
            return 0;
        }
        $this->outbox->queue($type, $recipient->channelConnectionId, $provider, $recipient->address, $body, $payload, dedupeKey: $dedupeKey);

        return 1;
    }

    /** @return array{\DateTimeImmutable, \DateTimeImmutable} */
    private function bounds(Organization $organization, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn): array
    {
        if ($endsOn < $startsOn) {
            throw new \InvalidArgumentException('Дата окончания должна быть не раньше даты начала.');
        }
        $timezone = new \DateTimeZone($organization->timezone());

        return [
            new \DateTimeImmutable($startsOn->format('Y-m-d').' 00:00:00', $timezone),
            new \DateTimeImmutable($endsOn->modify('+1 day')->format('Y-m-d').' 00:00:00', $timezone),
        ];
    }
}
