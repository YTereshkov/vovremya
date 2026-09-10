<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Catalog\Application\ServiceNotificationTemplateResolver;
use App\Module\Clients\Application\NotificationRecipient;
use App\Module\Clients\Application\NotificationRecipientResolver;
use App\Module\Communications\Application\MessageTemplateCatalog;
use App\Module\Communications\Application\NotificationOutbox;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Communications\Domain\Model\MessageTemplateType;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\AppointmentConfirmationAction;
use App\Module\Scheduling\Domain\Model\AppointmentConfirmationRequest;
use App\Module\Scheduling\Domain\Model\AppointmentConfirmationStatus;
use App\Module\Scheduling\Domain\Model\ConfirmationActionType;
use Symfony\Component\Uid\Ulid;

final readonly class AppointmentConfirmationService
{
    public function __construct(
        private AppointmentConfirmationStore $store,
        private NotificationRecipientResolver $recipients,
        private ServiceNotificationTemplateResolver $serviceTemplates,
        private MessageTemplateCatalog $templates,
        private NotificationOutbox $outbox,
    ) {
    }

    public function request(Ulid $appointmentId, \DateTimeImmutable $now): AppointmentConfirmationRequest
    {
        $appointment = $this->store->findAppointment($appointmentId)
            ?? throw new \OutOfBoundsException('Занятие не найдено.');
        if ($appointment->startsAt() <= $now) {
            throw new \DomainException('Нельзя запросить подтверждение для начавшегося занятия.');
        }
        $existing = $this->store->findByAppointment($appointment->id());
        if (null !== $existing) {
            return $existing;
        }
        $recipient = $this->recipients->primaryForClient($appointment->clientId())
            ?? throw new \DomainException('У клиента нет подключённого основного канала.');

        return $this->store->transactional(function () use ($appointment, $recipient, $now): AppointmentConfirmationRequest {
            $existing = $this->store->findByAppointment($appointment->id());
            if (null !== $existing) {
                return $existing;
            }
            $request = AppointmentConfirmationRequest::create($appointment, $recipient->channelConnectionId, $now);
            $this->store->save($request);
            $this->queue(
                $appointment,
                $recipient,
                'APPOINTMENT_CONFIRMATION_REQUEST',
                $this->createButtons($request, $now),
                'confirmation:'.$appointment->id()->toRfc4122(),
                $request->id(),
            );

            return $request;
        });
    }

    public function remind(Appointment $appointment, AppointmentConfirmationRequest $request, \DateTimeImmutable $now): void
    {
        if (null !== $request->reminderSentAt() || $appointment->startsAt() <= $now || !in_array($request->status(), [AppointmentConfirmationStatus::Pending, AppointmentConfirmationStatus::NoResponse], true)) {
            return;
        }
        $recipient = $this->recipients->byChannel($appointment->clientId(), $request->channelConnectionId())
            ?? throw new \DomainException('Канал запроса подтверждения больше недоступен.');
        $this->store->transactional(function () use ($appointment, $request, $recipient, $now): void {
            $this->queue(
                $appointment,
                $recipient,
                'APPOINTMENT_REMINDER',
                $this->createButtons($request, $now),
                'appointment-reminder:'.$appointment->id()->toRfc4122(),
                $request->id(),
            );
            $request->markReminderSent($now);
            $this->store->save($request);
        });
    }

    /** @return array{status: string, requestId: ?string} */
    public function status(Ulid $appointmentId): array
    {
        if (null === $this->store->findAppointment($appointmentId)) {
            throw new \OutOfBoundsException('Занятие не найдено.');
        }
        $request = $this->store->findByAppointment($appointmentId);

        return [
            'status' => $request?->status()->value ?? 'NOT_REQUESTED',
            'requestId' => $request?->id()->toRfc4122(),
        ];
    }

    /** @param array<string, mixed> $buttons */
    private function queue(
        Appointment $appointment,
        NotificationRecipient $recipient,
        string $type,
        array $buttons,
        string $dedupeKey,
        Ulid $requestId,
    ): void {
        $timezone = new \DateTimeZone($appointment->organization()->timezone());
        $start = $appointment->startsAt()->setTimezone($timezone);
        $body = $this->templates->render(
            MessageTemplateType::CONFIRMATION,
            [
                'date' => $start->format('d.m.Y'),
                'time' => $start->format('H:i'),
                'service' => $appointment->serviceName(),
                'client_name' => $recipient->clientName,
                'contact_name' => $recipient->contactName ?? $recipient->clientName,
            ],
            $this->serviceTemplates->confirmationTemplate($appointment->serviceId()),
        );
        $this->outbox->queue(
            $type,
            $recipient->channelConnectionId,
            CommunicationProvider::from($recipient->provider),
            $recipient->address,
            $body,
            ['appointmentId' => $appointment->id()->toRfc4122(), 'confirmationRequestId' => $requestId->toRfc4122()],
            $buttons,
            ['source' => 'appointment-confirmation'],
            $dedupeKey,
        );
    }

    private static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    /** @return list<list<array{label: string, action: string}>> */
    private function createButtons(AppointmentConfirmationRequest $request, \DateTimeImmutable $now): array
    {
        $confirmToken = self::token();
        $cannotToken = self::token();
        $confirm = AppointmentConfirmationAction::create($request, ConfirmationActionType::Confirm, $confirmToken, $now);
        $cannot = AppointmentConfirmationAction::create($request, ConfirmationActionType::CannotAttend, $cannotToken, $now);
        $this->store->save($confirm);
        $this->store->save($cannot);

        return [
            [['label' => 'Будем', 'action' => self::callback($confirm, $confirmToken)]],
            [['label' => 'Не сможем', 'action' => self::callback($cannot, $cannotToken)]],
        ];
    }

    private static function callback(AppointmentConfirmationAction $action, string $token): string
    {
        return 'confirmation:'.$action->id()->toRfc4122().':'.$token;
    }
}
