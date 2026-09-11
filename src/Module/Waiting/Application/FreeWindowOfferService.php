<?php

declare(strict_types=1);

namespace App\Module\Waiting\Application;

use App\Module\Catalog\Application\ServiceCatalog;
use App\Module\Clients\Application\NotificationRecipient;
use App\Module\Clients\Application\NotificationRecipientResolver;
use App\Module\Communications\Application\MessageTemplateCatalog;
use App\Module\Communications\Application\NotificationOutbox;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Communications\Domain\Model\MessageTemplateType;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Scheduling\Application\AppointmentStore;
use App\Module\Scheduling\Application\AvailabilityService;
use App\Module\Scheduling\Application\FreeWindowBookingService;
use App\Module\Scheduling\Application\ScheduleAllocationStore;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\ScheduleAllocation;
use App\Module\Scheduling\Domain\TimeUnavailable;
use App\Module\Waiting\Domain\Model\FreeWindow;
use App\Module\Waiting\Domain\Model\FreeWindowOffer;
use App\Module\Waiting\Domain\Model\FreeWindowOfferStatus;
use App\Module\Waiting\Domain\Model\FreeWindowOfferTargetType;
use App\Module\Waiting\Domain\Model\FreeWindowStatus;
use Symfony\Component\Uid\Ulid;

final readonly class FreeWindowOfferService
{
    public function __construct(
        private FreeWindowOfferStore $offers,
        private FreeWindowStore $windows,
        private WaitingListStore $waiting,
        private FreeWindowMatchingService $matching,
        private AppointmentStore $appointments,
        private ServiceCatalog $services,
        private AvailabilityService $availability,
        private ScheduleAllocationStore $allocations,
        private FreeWindowBookingService $booking,
        private NotificationRecipientResolver $recipients,
        private NotificationOutbox $outbox,
        private MessageTemplateCatalog $templates,
    ) {
    }

    public function create(
        AdministratorAccount $actor,
        Ulid $windowId,
        FreeWindowOfferTargetType $targetType,
        Ulid $candidateId,
        \DateTimeImmutable $now,
    ): FreeWindowOffer {
        return $this->offers->transactional(function () use ($actor, $windowId, $targetType, $candidateId, $now): FreeWindowOffer {
            $window = $this->windows->lock($windowId) ?? throw new \OutOfBoundsException('Свободное окно не найдено.');
            if (FreeWindowStatus::Open !== $window->status() || $window->startsAt() <= $now) {
                throw new \DomainException('Свободное окно больше недоступно.');
            }
            if (null !== $this->offers->activeForWindow($window->id())) {
                throw new \DomainException('Это окно уже предложено другому клиенту.');
            }
            $decision = $this->availability->check($window->specialistId(), $window->startsAt(), $window->endsAt());
            if (!$decision->available) {
                throw new TimeUnavailable($decision->conflict ?? throw new \LogicException('Missing availability conflict.'));
            }

            $candidate = $this->candidate($actor, $window, $targetType, $candidateId, $now);
            $recipient = $this->recipients->primaryForClient($candidate['clientId'])
                ?? throw new \DomainException('У клиента нет подключённого основного канала.');
            $acceptToken = self::token();
            $declineToken = self::token();
            $offer = FreeWindowOffer::create(
                $window,
                $candidate['clientId'],
                $candidate['clientName'],
                $recipient->channelConnectionId,
                $targetType,
                $candidate['appointmentId'],
                $candidate['waitingListEntryId'],
                $candidate['serviceDefaultDuration'],
                $candidate['serviceMinimumDuration'],
                $candidate['serviceMaximumDuration'],
                $candidate['durationMinutes'],
                $acceptToken,
                $declineToken,
                $now,
            );
            $this->offers->save($offer);
            $this->allocations->save(ScheduleAllocation::forOfferReservation(
                $actor->organization(),
                $window->specialistId(),
                $offer->id(),
                $window->startsAt(),
                $window->endsAt(),
            ));
            $this->queueOffer($offer, $window, $recipient, $acceptToken, $declineToken, $actor->organization()->timezone());

            return $offer;
        });
    }

    public function accept(Ulid $offerId, string $token, Ulid $channelConnectionId, \DateTimeImmutable $now): FreeWindowOfferResponse
    {
        return $this->offers->transactional(function () use ($offerId, $token, $channelConnectionId, $now): FreeWindowOfferResponse {
            $offer = $this->offers->lock($offerId);
            if (null === $offer || !$offer->authorizeAccept($token, $channelConnectionId)) {
                return FreeWindowOfferResponse::Ignored;
            }
            $window = $this->windows->lock($offer->freeWindowId());
            if (null === $window || FreeWindowStatus::Open !== $window->status() || $window->startsAt() <= $now) {
                $this->cancelUnavailable($offer, $window, $now);

                return FreeWindowOfferResponse::Unavailable;
            }

            try {
                if (FreeWindowOfferTargetType::WaitingList === $offer->targetType()) {
                    $appointment = $this->booking->createOneOff(
                        $window->organization(),
                        $offer->id(),
                        $window->specialistId(),
                        $offer->clientId(),
                        $offer->serviceId(),
                        $offer->serviceName(),
                        $offer->serviceDefaultDuration(),
                        $offer->serviceMinimumDuration(),
                        $offer->serviceMaximumDuration(),
                        $offer->appointmentDurationMinutes(),
                        $window->startsAt(),
                        $now,
                    );
                } else {
                    $move = $this->booking->moveEarlier(
                        $offer->id(),
                        $offer->candidateAppointmentId() ?? throw new \LogicException('Missing candidate appointment.'),
                        $offer->clientId(),
                        $window->specialistId(),
                        $window->serviceId(),
                        $window->startsAt(),
                        $window->endsAt(),
                        $now,
                    );
                    $appointment = $move->replacement;
                    $vacated = $this->windows->findBySourceAppointment($move->source->id());
                    if (null === $vacated) {
                        $vacated = FreeWindow::create(
                            $move->source->organization(),
                            $move->source->id(),
                            $move->source->specialistId(),
                            $move->source->serviceId(),
                            $move->source->serviceName(),
                            $move->source->durationMinutes(),
                            $move->source->startsAt(),
                            $move->source->endsAt(),
                            $now,
                        );
                    } else {
                        $vacated->reopen();
                    }
                    $this->windows->save($vacated);
                }
            } catch (TimeUnavailable|\DomainException) {
                $this->cancelUnavailable($offer, $window, $now);

                return FreeWindowOfferResponse::Unavailable;
            }

            $window->close('OFFER_ACCEPTED', $now);
            $offer->accept($appointment->id(), $now);
            $this->windows->save($window);
            $this->offers->save($offer);

            return FreeWindowOfferResponse::Accepted;
        });
    }

    public function decline(Ulid $offerId, string $token, Ulid $channelConnectionId, \DateTimeImmutable $now): bool
    {
        return $this->offers->transactional(function () use ($offerId, $token, $channelConnectionId, $now): bool {
            $offer = $this->offers->lock($offerId);
            if (null === $offer || !$offer->authorizeDecline($token, $channelConnectionId)) {
                return false;
            }
            $offer->decline($now);
            $this->allocations->releaseForOffer($offer->id());
            $this->offers->save($offer);

            return true;
        });
    }

    public function cancel(AdministratorAccount $actor, Ulid $offerId, \DateTimeImmutable $now): void
    {
        $this->offers->transactional(function () use ($actor, $offerId, $now): void {
            $offer = $this->offers->lock($offerId) ?? throw new \OutOfBoundsException('Предложение не найдено.');
            if (FreeWindowOfferStatus::Active !== $offer->status()) {
                throw new \DomainException('Предложение уже завершено.');
            }
            $offer->cancel('ADMIN_CANCELLED', $now);
            $this->allocations->releaseForOffer($offer->id());
            $this->offers->save($offer);
            $this->queueCancellation($offer, 'Предложение свободного времени отменено администратором. Окно снова доступно.');
        });
    }

    public function cancelActiveForWindow(Ulid $windowId, string $reason, \DateTimeImmutable $now): void
    {
        $offer = $this->offers->activeForWindow($windowId);
        if (null === $offer) {
            return;
        }
        $this->offers->transactional(function () use ($offer, $reason, $now): void {
            $locked = $this->offers->lock($offer->id());
            if (null === $locked || FreeWindowOfferStatus::Active !== $locked->status()) {
                return;
            }
            $locked->cancel($reason, $now);
            $this->allocations->releaseForOffer($locked->id());
            $this->offers->save($locked);
            $this->queueCancellation($locked, 'Предложенное свободное время больше недоступно.');
        });
    }

    /** @return array<string, mixed> */
    public function present(FreeWindowOffer $offer): array
    {
        return [
            'id' => $offer->id()->toRfc4122(),
            'client' => ['id' => $offer->clientId()->toRfc4122(), 'name' => $offer->clientName()],
            'targetType' => $offer->targetType()->value,
            'status' => $offer->status()->value,
            'createdAt' => $offer->createdAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
    }

    /**
     * @return array{clientId: Ulid, clientName: string, appointmentId: ?Ulid, waitingListEntryId: ?Ulid, serviceDefaultDuration: int, serviceMinimumDuration: ?int, serviceMaximumDuration: ?int, durationMinutes: int}
     */
    private function candidate(AdministratorAccount $actor, FreeWindow $window, FreeWindowOfferTargetType $type, Ulid $candidateId, \DateTimeImmutable $now): array
    {
        $candidates = $this->matching->candidates($window->id()->toRfc4122(), $actor->organization(), $now);
        if (FreeWindowOfferTargetType::MoveEarlier === $type) {
            foreach ($candidates['moveEarlier'] as $candidate) {
                if ($candidate['appointmentId'] !== $candidateId->toRfc4122()) {
                    continue;
                }
                $appointment = $this->appointments->find($candidateId) ?? throw new \DomainException('Клиент больше не подходит для этого окна.');

                return [
                    'clientId' => $appointment->clientId(),
                    'clientName' => $candidate['client']['name'],
                    'appointmentId' => $appointment->id(),
                    'waitingListEntryId' => null,
                    'serviceDefaultDuration' => $appointment->serviceDefaultDuration(),
                    'serviceMinimumDuration' => $appointment->serviceMinimumDuration(),
                    'serviceMaximumDuration' => $appointment->serviceMaximumDuration(),
                    'durationMinutes' => $appointment->durationMinutes(),
                ];
            }
        } else {
            foreach ($candidates['waitingClients'] as $candidate) {
                if ($candidate['waitingListEntryId'] !== $candidateId->toRfc4122()) {
                    continue;
                }
                $entry = $this->waiting->find($candidateId);
                $service = $this->services->find($window->serviceId()->toRfc4122());
                if (null === $entry || !$entry->active() || !$entry->clientId()->equals(Ulid::fromString($candidate['client']['id']))) {
                    break;
                }

                return [
                    'clientId' => $entry->clientId(),
                    'clientName' => $candidate['client']['name'],
                    'appointmentId' => null,
                    'waitingListEntryId' => $entry->id(),
                    'serviceDefaultDuration' => $service->defaultDurationMinutes(),
                    'serviceMinimumDuration' => $service->minimumDurationMinutes(),
                    'serviceMaximumDuration' => $service->maximumDurationMinutes(),
                    'durationMinutes' => $service->defaultDurationMinutes(),
                ];
            }
        }

        throw new \DomainException('Клиент больше не подходит для этого окна.');
    }

    private function queueOffer(FreeWindowOffer $offer, FreeWindow $window, NotificationRecipient $recipient, string $acceptToken, string $declineToken, string $timezone): void
    {
        $zone = new \DateTimeZone($timezone);
        $start = $window->startsAt()->setTimezone($zone);
        $end = $start->modify(sprintf('+%d minutes', $offer->appointmentDurationMinutes()));
        $body = $this->templates->render(MessageTemplateType::FREE_WINDOW, [
            'date' => $start->format('d.m.Y'),
            'time' => $start->format('H:i').'–'.$end->format('H:i'),
            'service' => $offer->serviceName(),
            'client_name' => $recipient->clientName,
            'contact_name' => $recipient->contactName ?? $recipient->clientName,
        ]).' Это время временно предложено вам.';
        $this->outbox->queue(
            'FREE_WINDOW_OFFER',
            $recipient->channelConnectionId,
            CommunicationProvider::from($recipient->provider),
            $recipient->address,
            $body,
            ['freeWindowId' => $window->id()->toRfc4122(), 'freeWindowOfferId' => $offer->id()->toRfc4122()],
            [
                [['label' => 'Да, подходит', 'action' => 'free-window-offer-accept:'.$offer->id()->toRfc4122().':'.$acceptToken]],
                [['label' => 'Не подходит', 'action' => 'free-window-offer-decline:'.$offer->id()->toRfc4122().':'.$declineToken]],
            ],
            ['source' => 'free-window-offer'],
            'free-window-offer:'.$offer->id()->toRfc4122(),
        );
    }

    private function queueCancellation(FreeWindowOffer $offer, string $body): void
    {
        $recipient = $this->recipients->byChannel($offer->clientId(), $offer->channelConnectionId());
        if (null === $recipient) {
            return;
        }
        $this->outbox->queue(
            'FREE_WINDOW_OFFER_CANCELLED',
            $recipient->channelConnectionId,
            CommunicationProvider::from($recipient->provider),
            $recipient->address,
            $body,
            ['freeWindowOfferId' => $offer->id()->toRfc4122()],
            metadata: ['source' => 'free-window-offer-cancelled'],
            dedupeKey: 'free-window-offer-cancelled:'.$offer->id()->toRfc4122(),
        );
    }

    private function cancelUnavailable(FreeWindowOffer $offer, ?FreeWindow $window, \DateTimeImmutable $now): void
    {
        $offer->cancel('WINDOW_UNAVAILABLE', $now);
        $this->allocations->releaseForOffer($offer->id());
        $this->offers->save($offer);
        if (null !== $window) {
            $window->close('OFFER_UNAVAILABLE', $now);
            $this->windows->save($window);
        }
        $this->queueCancellation($offer, 'Предложенное свободное время больше недоступно.');
    }

    private static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }
}
