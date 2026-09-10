<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Clients\Application\NotificationRecipient;
use App\Module\Clients\Application\NotificationRecipientResolver;
use App\Module\Communications\Application\MessageTemplateCatalog;
use App\Module\Communications\Application\NotificationOutbox;
use App\Module\Communications\Application\PendingAppointmentNotificationCancellation;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Communications\Domain\Model\MessageTemplateType;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Scheduling\Domain\AvailabilityWarning;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\AppointmentPlanningStatus;
use App\Module\Scheduling\Domain\Model\ScheduleAllocation;
use App\Module\Scheduling\Domain\Model\TransferOption;
use App\Module\Scheduling\Domain\Model\TransferRequest;
use App\Module\Scheduling\Domain\Model\TransferRequestStatus;
use App\Module\Scheduling\Domain\SoftWarningsRequired;
use App\Module\Scheduling\Domain\TimeUnavailable;
use App\Module\Waiting\Application\FreeWindowManager;
use Symfony\Component\Uid\Ulid;

final readonly class TransferService
{
    public function __construct(
        private TransferStore $transfers,
        private AppointmentStore $appointments,
        private ScheduleAllocationStore $allocations,
        private AvailabilityService $availability,
        private SoftWarningService $warnings,
        private NotificationRecipientResolver $recipients,
        private NotificationOutbox $outbox,
        private MessageTemplateCatalog $templates,
        private PendingAppointmentNotificationCancellation $notificationCancellation,
        private FreeWindowManager $freeWindows,
        private AppointmentHistoryRecorder $history,
    ) {
    }

    public function requestFromClient(Ulid $appointmentId, Ulid $channelConnectionId, \DateTimeImmutable $now): TransferRequest
    {
        $appointment = $this->transferableAppointment($appointmentId, $now);
        $existing = $this->transfers->findActiveForAppointment($appointmentId);
        if (null !== $existing) {
            return $existing;
        }
        $request = TransferRequest::open($appointment, $channelConnectionId, $now);
        $this->transfers->save($request);
        $this->history->record($appointment, 'TRANSFER_REQUESTED', ['source' => 'CLIENT'], null, $now);

        return $request;
    }

    /**
     * @param list<\DateTimeImmutable> $startsAt
     * @param list<string> $acceptedWarnings
     * @return array{request: TransferRequest, options: list<TransferOption>}
     */
    public function offer(AdministratorAccount $actor, Ulid $appointmentId, array $startsAt, array $acceptedWarnings, \DateTimeImmutable $now): array
    {
        $appointment = $this->transferableAppointment($appointmentId, $now);
        if ([] === $startsAt || 10 < count($startsAt)) {
            throw new \InvalidArgumentException('Выберите от одного до десяти вариантов времени.');
        }
        $request = $this->transfers->findActiveForAppointment($appointmentId);
        if (null === $request) {
            $recipient = $this->recipientForRequest($appointment);
            $request = TransferRequest::open($appointment, $recipient->channelConnectionId, $now);
        } else {
            $recipient = $this->recipients->byChannel($appointment->clientId(), $request->channelConnectionId())
                ?? throw new \DomainException('Канал исходного запроса переноса больше недоступен.');
        }
        if (TransferRequestStatus::AwaitingOptions !== $request->status()) {
            throw new \DomainException('Варианты переноса уже предложены.');
        }

        $accepted = array_fill_keys($acceptedWarnings, true);
        $allWarnings = [];
        $normalized = [];
        foreach ($startsAt as $start) {
            $start = $start->setTimezone(new \DateTimeZone('UTC'));
            if ($start <= $now || $start == $appointment->startsAt()) {
                throw new \InvalidArgumentException('Вариант переноса должен быть будущим новым временем.');
            }
            $key = $start->format('Y-m-d\TH:i:sP');
            if (isset($normalized[$key])) {
                throw new \InvalidArgumentException('Варианты времени не должны повторяться.');
            }
            $end = $start->modify(sprintf('+%d minutes', $appointment->durationMinutes()));
            $decision = $this->availability->check($appointment->specialistId(), $start, $end, $appointment->id());
            if (!$decision->available) {
                throw new TimeUnavailable($decision->conflict ?? throw new \LogicException('Missing availability conflict.'));
            }
            foreach ($this->warnings->check($appointment->specialistId(), $start, $end, $appointment->id()) as $warning) {
                $allWarnings[$warning->code] = $warning;
            }
            $normalized[$key] = [$start, $end];
        }
        $missing = array_filter($allWarnings, static fn (AvailabilityWarning $warning): bool => !isset($accepted[$warning->code]));
        if ([] !== $missing) {
            throw new SoftWarningsRequired(array_values($allWarnings));
        }

        return $this->transfers->transactional(function () use ($actor, $appointment, $request, $recipient, $normalized, $allWarnings, $now): array {
            $declineToken = self::token();
            $request->offer($declineToken, $now);
            $options = [];
            $buttons = [];
            foreach ($normalized as [$start, $end]) {
                $token = self::token();
                $option = TransferOption::offer($request, $start, $end, $token, $now);
                $options[] = $option;
                $buttons[] = [[
                    'label' => $this->optionLabel($start, $end, $appointment->organization()->timezone()),
                    'action' => 'transfer-option:'.$option->id()->toRfc4122().':'.$token,
                ]];
            }
            $buttons[] = [['label' => 'Ни одно время не подходит', 'action' => 'transfer-decline:'.$request->id()->toRfc4122().':'.$declineToken]];
            $this->transfers->save($request, ...$options);
            $sourceStart = $appointment->startsAt()->setTimezone(new \DateTimeZone($appointment->organization()->timezone()));
            $body = $this->templates->render(MessageTemplateType::TRANSFER, [
                'date' => $sourceStart->format('d.m.Y'),
                'time' => $sourceStart->format('H:i'),
                'service' => $appointment->serviceName(),
                'client_name' => $recipient->clientName,
                'contact_name' => $recipient->contactName ?? $recipient->clientName,
            ]);
            $this->outbox->queue(
                'TRANSFER_OPTIONS',
                $recipient->channelConnectionId,
                CommunicationProvider::from($recipient->provider),
                $recipient->address,
                $body,
                ['appointmentId' => $appointment->id()->toRfc4122(), 'transferRequestId' => $request->id()->toRfc4122()],
                $buttons,
                ['source' => 'transfer-options'],
                'transfer-options:'.$request->id()->toRfc4122(),
            );
            $this->history->record($appointment, 'TRANSFER_OPTIONS_SENT', [
                'transferRequestId' => $request->id()->toRfc4122(),
                'options' => array_map(static fn (TransferOption $option): array => [
                    'startsAt' => $option->startsAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
                    'endsAt' => $option->endsAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
                ], $options),
                'warnings' => array_map(static fn (AvailabilityWarning $warning): array => $warning->toArray(), array_values($allWarnings)),
            ], $actor->id(), $now);

            return ['request' => $request, 'options' => $options];
        });
    }

    public function selectFromCallback(Ulid $optionId, string $token, Ulid $channelConnectionId, \DateTimeImmutable $now): TransferSelectionResult
    {
        $option = $this->transfers->findOption($optionId);
        if (null === $option || !$option->authorize($token)) {
            return TransferSelectionResult::Ignored;
        }
        $request = $this->transfers->find($option->transferRequestId());
        if (null === $request || !$request->channelConnectionId()->equals($channelConnectionId) || TransferRequestStatus::OptionsSent !== $request->status()) {
            return TransferSelectionResult::Ignored;
        }

        try {
            if (!$this->complete($request->id(), $optionId, $token, $channelConnectionId, $now)) {
                return TransferSelectionResult::Ignored;
            }

            return TransferSelectionResult::Completed;
        } catch (TimeUnavailable) {
            $this->notifyUnavailable($request, $option);

            return TransferSelectionResult::Unavailable;
        }
    }

    public function declineFromCallback(Ulid $requestId, string $token, Ulid $channelConnectionId, \DateTimeImmutable $now): bool
    {
        return $this->transfers->transactional(function () use ($requestId, $token, $channelConnectionId, $now): bool {
            $request = $this->transfers->lock($requestId);
            if (null === $request || !$request->decline($token, $channelConnectionId, $now)) {
                return false;
            }
            $this->transfers->save($request);
            $this->history->recordByAppointmentId($request->appointmentId(), 'TRANSFER_DECLINED', ['transferRequestId' => $request->id()->toRfc4122()], null, $now);

            return true;
        });
    }

    public function cancel(AdministratorAccount $actor, Ulid $requestId, \DateTimeImmutable $now): void
    {
        $this->transfers->transactional(function () use ($actor, $requestId, $now): void {
            $request = $this->transfers->lock($requestId) ?? throw new \OutOfBoundsException('Запрос переноса не найден.');
            $request->cancel($now);
            $this->transfers->save($request);
            $this->history->recordByAppointmentId($request->appointmentId(), 'TRANSFER_CANCELLED', ['transferRequestId' => $request->id()->toRfc4122()], $actor->id(), $now);
        });
    }

    public function cancelActiveForAppointment(Ulid $appointmentId, ?Ulid $actorId, string $reason, \DateTimeImmutable $now): bool
    {
        $active = $this->transfers->findActiveForAppointment($appointmentId);
        if (null === $active) {
            return false;
        }

        return $this->transfers->transactional(function () use ($active, $actorId, $reason, $now): bool {
            $request = $this->transfers->lock($active->id());
            if (null === $request || !in_array($request->status(), [TransferRequestStatus::AwaitingOptions, TransferRequestStatus::OptionsSent], true)) {
                return false;
            }
            $request->cancel($now);
            $this->transfers->save($request);
            $this->history->recordByAppointmentId($request->appointmentId(), 'TRANSFER_CANCELLED', [
                'transferRequestId' => $request->id()->toRfc4122(),
                'reason' => $reason,
            ], $actorId, $now);

            return true;
        });
    }

    /** @return array{request: ?array<string, mixed>, options: list<array<string, mixed>>} */
    public function state(Ulid $appointmentId): array
    {
        if (null === $this->appointments->find($appointmentId)) {
            throw new \OutOfBoundsException('Занятие не найдено.');
        }
        $request = $this->transfers->findActiveForAppointment($appointmentId);
        if (null === $request) {
            return ['request' => null, 'options' => []];
        }

        return ['request' => $this->presentRequest($request), 'options' => array_map($this->presentOption(...), $this->transfers->options($request->id()))];
    }

    private function complete(Ulid $requestId, Ulid $optionId, string $token, Ulid $channelConnectionId, \DateTimeImmutable $now): bool
    {
        return $this->transfers->transactional(function () use ($requestId, $optionId, $token, $channelConnectionId, $now): bool {
            $candidate = $this->transfers->find($requestId) ?? throw new \OutOfBoundsException('Запрос переноса не найден.');
            $source = $this->appointments->lock($candidate->appointmentId()) ?? throw new \OutOfBoundsException('Занятие не найдено.');
            $request = $this->transfers->lock($requestId) ?? throw new \OutOfBoundsException('Запрос переноса не найден.');
            $option = $this->transfers->findOption($optionId);
            if (TransferRequestStatus::OptionsSent !== $request->status()
                || !$request->channelConnectionId()->equals($channelConnectionId)
                || null === $option
                || !$option->transferRequestId()->equals($request->id())
                || !$option->authorize($token)) {
                return false;
            }
            $this->assertTransferableAppointment($source, $now);
            $decision = $this->availability->check($source->specialistId(), $option->startsAt(), $option->endsAt(), $source->id());
            if (!$decision->available) {
                throw new TimeUnavailable($decision->conflict ?? throw new \LogicException('Missing availability conflict.'));
            }
            $newAppointment = Appointment::rescheduledFrom($source, $option->startsAt());
            $this->allocations->releaseForAppointment($source->id());
            $this->allocations->save(ScheduleAllocation::forAppointment(
                $source->organization(),
                $source->specialistId(),
                $newAppointment->id(),
                $newAppointment->startsAt(),
                $newAppointment->endsAt(),
            ));
            $this->appointments->save($newAppointment);
            $source->recordRescheduled($now);
            $this->notificationCancellation->cancel($source->id());
            $this->freeWindows->closeForAppointment($source->id(), 'APPOINTMENT_RESCHEDULED', $now);
            $this->appointments->save($source);
            $option->select($now);
            $request->complete($newAppointment->id(), $now);
            $this->transfers->save($option, $request);
            $payload = [
                'transferRequestId' => $request->id()->toRfc4122(),
                'from' => $source->startsAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
                'to' => $newAppointment->startsAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
                'newAppointmentId' => $newAppointment->id()->toRfc4122(),
            ];
            $this->history->record($source, 'APPOINTMENT_RESCHEDULED', $payload, null, $now);
            $this->history->record($newAppointment, 'CREATED_BY_TRANSFER', [
                'fromAppointmentId' => $source->id()->toRfc4122(),
                'from' => $source->startsAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
            ], null, $now);

            return true;
        });
    }

    private function notifyUnavailable(TransferRequest $request, TransferOption $option): void
    {
        $appointment = $this->appointments->find($request->appointmentId());
        if (null === $appointment) {
            return;
        }
        $recipient = $this->recipients->byChannel($appointment->clientId(), $request->channelConnectionId());
        if (null === $recipient) {
            return;
        }
        $this->outbox->queue(
            'TRANSFER_OPTION_UNAVAILABLE',
            $recipient->channelConnectionId,
            CommunicationProvider::from($recipient->provider),
            $recipient->address,
            'Выбранное время больше недоступно. Пожалуйста, выберите другой предложенный вариант.',
            ['appointmentId' => $appointment->id()->toRfc4122(), 'transferRequestId' => $request->id()->toRfc4122(), 'transferOptionId' => $option->id()->toRfc4122()],
            dedupeKey: 'transfer-unavailable:'.$request->id()->toRfc4122().':'.$option->id()->toRfc4122(),
        );
    }

    private function transferableAppointment(Ulid $id, \DateTimeImmutable $now): Appointment
    {
        $appointment = $this->appointments->find($id) ?? throw new \OutOfBoundsException('Занятие не найдено.');

        $this->assertTransferableAppointment($appointment, $now);

        return $appointment;
    }

    private function assertTransferableAppointment(Appointment $appointment, \DateTimeImmutable $now): void
    {
        if (AppointmentPlanningStatus::Planned !== $appointment->planningStatus() || null !== $appointment->resultStatus()) {
            throw new \DomainException('Перенос доступен только для запланированного занятия.');
        }
        if ($appointment->startsAt() <= $now) {
            throw new \DomainException('Начавшееся занятие нельзя перенести.');
        }

    }

    private function recipientForRequest(Appointment $appointment): NotificationRecipient
    {
        return $this->recipients->primaryForClient($appointment->clientId())
            ?? throw new \DomainException('У клиента нет подключённого основного канала.');
    }

    private function optionLabel(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, string $timezone): string
    {
        $zone = new \DateTimeZone($timezone);

        return sprintf('%s · %s–%s', $startsAt->setTimezone($zone)->format('d.m.Y'), $startsAt->setTimezone($zone)->format('H:i'), $endsAt->setTimezone($zone)->format('H:i'));
    }

    /** @return array<string, mixed> */
    private function presentRequest(TransferRequest $request): array
    {
        return [
            'id' => $request->id()->toRfc4122(),
            'status' => $request->status()->value,
            'newAppointmentId' => $request->newAppointmentId()?->toRfc4122(),
            'createdAt' => $request->createdAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
    }

    /** @return array<string, mixed> */
    private function presentOption(TransferOption $option): array
    {
        return [
            'id' => $option->id()->toRfc4122(),
            'startsAt' => $option->startsAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
            'endsAt' => $option->endsAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
            'selectedAt' => $option->selectedAt()?->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
    }

    private static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }
}
