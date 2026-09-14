<?php

declare(strict_types=1);

namespace App\Module\Waiting\Application;

use App\Module\Clients\Application\NotificationRecipient;
use App\Module\Clients\Application\NotificationRecipientResolver;
use App\Module\Communications\Application\MessageTemplateCatalog;
use App\Module\Communications\Application\NotificationOutbox;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Communications\Domain\Model\MessageTemplateType;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Scheduling\Application\RegularScheduleCreator;
use App\Module\Scheduling\Application\ScheduleAllocationStore;
use App\Module\Waiting\Domain\Model\PermanentPlace;
use App\Module\Waiting\Domain\Model\PermanentPlaceOffer;
use App\Module\Waiting\Domain\Model\PermanentPlaceOfferStatus;
use App\Module\Waiting\Domain\Model\PermanentPlaceSlot;
use App\Module\Waiting\Domain\Model\PermanentPlaceStatus;
use Symfony\Component\Uid\Ulid;

final readonly class PermanentPlaceOfferService
{
    public function __construct(
        private PermanentPlaceStore $places,
        private PermanentPlaceOfferStore $offers,
        private WaitingListStore $waiting,
        private PermanentPlaceMatchingService $matching,
        private PermanentPlaceReservationService $reservations,
        private RegularScheduleCreator $schedules,
        private ScheduleAllocationStore $allocations,
        private NotificationRecipientResolver $recipients,
        private NotificationOutbox $outbox,
        private MessageTemplateCatalog $templates,
    ) {
    }

    public function create(AdministratorAccount $actor, Ulid $placeId, Ulid $waitingListEntryId, \DateTimeImmutable $now): PermanentPlaceOffer
    {
        return $this->offers->transactional(function () use ($actor, $placeId, $waitingListEntryId, $now): PermanentPlaceOffer {
            $place = $this->places->lock($placeId) ?? throw new \OutOfBoundsException('Постоянное место не найдено.');
            if (PermanentPlaceStatus::Open !== $place->status()) {
                throw new \DomainException('Постоянное место больше недоступно.');
            }
            if (null !== $this->offers->activeForPlace($place->id())) {
                throw new \DomainException('Это место уже предложено другому клиенту.');
            }
            $candidate = $this->candidate($place, $waitingListEntryId);
            $entry = $this->waiting->find($waitingListEntryId) ?? throw new \DomainException('Клиент больше не подходит для этого места.');
            $recipient = $this->recipients->primaryForClient($entry->clientId())
                ?? throw new \DomainException('У клиента нет подключённого основного канала.');
            $acceptToken = self::token();
            $declineToken = self::token();
            $offer = PermanentPlaceOffer::create($place, $entry, $candidate['client']['name'], $recipient->channelConnectionId, $acceptToken, $declineToken, $now);
            $this->offers->save($offer);
            $slots = $this->places->slots($place->id());
            $this->reservations->reserve($place, $slots, $offer->id(), $now);
            $this->queueOffer($offer, $place, $slots, $recipient, $acceptToken, $declineToken, $actor->organization()->timezone());

            return $offer;
        });
    }

    public function accept(Ulid $offerId, string $token, Ulid $channelConnectionId, \DateTimeImmutable $now): PermanentPlaceOfferResponse
    {
        return $this->offers->transactional(function () use ($offerId, $token, $channelConnectionId, $now): PermanentPlaceOfferResponse {
            $offer = $this->offers->lock($offerId);
            if (null === $offer || !$offer->authorizeAccept($token, $channelConnectionId)) {
                return PermanentPlaceOfferResponse::Ignored;
            }
            $place = $this->places->lock($offer->permanentPlaceId());
            if (null === $place || PermanentPlaceStatus::Open !== $place->status()) {
                $this->cancelUnavailable($offer, $now);

                return PermanentPlaceOfferResponse::Unavailable;
            }
            try {
                $candidate = $this->candidate($place, $offer->waitingListEntryId());
                if ($candidate['client']['id'] !== $offer->clientId()->toRfc4122()) {
                    throw new \DomainException('Клиент больше не подходит для этого места.');
                }
                $slots = $this->places->slots($place->id());
                $this->reservations->reserve($place, $slots, $offer->id(), $now);
                $schedule = $this->schedules->createFromPermanentPlace(
                    $place->organization(),
                    $offer->id(),
                    $place->specialistId(),
                    $offer->clientId(),
                    $place->serviceId(),
                    $place->availableFrom(),
                    array_map(static fn (PermanentPlaceSlot $slot): array => [
                        'weekday' => $slot->weekday(),
                        'startTime' => $slot->startTime(),
                        'durationMinutes' => $slot->durationMinutes(),
                    ], $slots),
                    $now,
                );
            } catch (\DomainException|\OutOfBoundsException) {
                $this->cancelUnavailable($offer, $now);

                return PermanentPlaceOfferResponse::Unavailable;
            }
            $place->claim($schedule->id(), $now);
            $offer->accept($schedule->id(), $now);
            $this->places->save($place);
            $this->offers->save($offer);

            return PermanentPlaceOfferResponse::Accepted;
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

    public function cancel(Ulid $offerId, \DateTimeImmutable $now): void
    {
        $this->offers->transactional(function () use ($offerId, $now): void {
            $offer = $this->offers->lock($offerId) ?? throw new \OutOfBoundsException('Предложение не найдено.');
            if (PermanentPlaceOfferStatus::Active !== $offer->status()) {
                throw new \DomainException('Предложение уже завершено.');
            }
            $offer->cancel('ADMIN_CANCELLED', $now);
            $this->allocations->releaseForOffer($offer->id());
            $this->offers->save($offer);
            $this->queueCancellation($offer, 'Предложение постоянного места отменено администратором. Место снова доступно.');
        });
    }

    public function extendActive(\DateTimeImmutable $now): void
    {
        foreach ($this->offers->active() as $offer) {
            $this->offers->transactional(function () use ($offer, $now): void {
                $locked = $this->offers->lock($offer->id());
                if (null === $locked || PermanentPlaceOfferStatus::Active !== $locked->status()) {
                    return;
                }
                $place = $this->places->lock($locked->permanentPlaceId());
                if (null === $place || PermanentPlaceStatus::Open !== $place->status()) {
                    $this->cancelUnavailable($locked, $now);

                    return;
                }
                try {
                    $this->reservations->reserve($place, $this->places->slots($place->id()), $locked->id(), $now);
                } catch (\DomainException) {
                    $this->cancelUnavailable($locked, $now);
                }
            });
        }
    }

    /** @return array<string, mixed> */
    public function present(PermanentPlaceOffer $offer): array
    {
        return [
            'id' => $offer->id()->toRfc4122(),
            'client' => ['id' => $offer->clientId()->toRfc4122(), 'name' => $offer->clientName()],
            'status' => $offer->status()->value,
            'createdAt' => $offer->createdAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
    }

    /** @return array<string, mixed> */
    private function candidate(PermanentPlace $place, Ulid $waitingListEntryId): array
    {
        foreach ($this->matching->candidates($place->id()) as $candidate) {
            if ($candidate['waitingListEntryId'] === $waitingListEntryId->toRfc4122()) {
                return $candidate;
            }
        }

        throw new \DomainException('Клиент больше не подходит для этого места.');
    }

    /** @param list<PermanentPlaceSlot> $slots */
    private function queueOffer(PermanentPlaceOffer $offer, PermanentPlace $place, array $slots, NotificationRecipient $recipient, string $acceptToken, string $declineToken, string $timezone): void
    {
        $weekdays = [1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье'];
        $times = array_map(static function (PermanentPlaceSlot $slot) use ($weekdays): string {
            $end = (new \DateTimeImmutable('2000-01-01 '.$slot->startTime()))->modify(sprintf('+%d minutes', $slot->durationMinutes()));

            return sprintf('%s %s–%s', $weekdays[$slot->weekday()], $slot->startTime(), $end->format('H:i'));
        }, $slots);
        $date = (new \DateTimeImmutable($place->availableFrom()->format('Y-m-d'), new \DateTimeZone($timezone)))->format('d.m.Y');
        $body = $this->templates->render(MessageTemplateType::PERMANENT_PLACE, [
            'date' => 'с '.$date,
            'time' => implode('; ', $times),
            'service' => $place->serviceName(),
            'client_name' => $recipient->clientName,
            'contact_name' => $recipient->contactName ?? $recipient->clientName,
        ]).' Комплект временно закреплён за вами.';
        $this->outbox->queue(
            'PERMANENT_PLACE_OFFER',
            $recipient->channelConnectionId,
            CommunicationProvider::from($recipient->provider),
            $recipient->address,
            $body,
            ['permanentPlaceId' => $place->id()->toRfc4122(), 'permanentPlaceOfferId' => $offer->id()->toRfc4122()],
            [
                [['label' => 'Да, записаться', 'action' => 'permanent-place-offer-accept:'.$offer->id()->toRfc4122().':'.$acceptToken]],
                [['label' => 'Не подходит', 'action' => 'permanent-place-offer-decline:'.$offer->id()->toRfc4122().':'.$declineToken]],
            ],
            ['source' => 'permanent-place-offer'],
            'permanent-place-offer:'.$offer->id()->toRfc4122(),
        );
    }

    private function queueCancellation(PermanentPlaceOffer $offer, string $body): void
    {
        $recipient = $this->recipients->byChannel($offer->clientId(), $offer->channelConnectionId());
        if (null === $recipient) {
            return;
        }
        $this->outbox->queue(
            'PERMANENT_PLACE_OFFER_CANCELLED',
            $recipient->channelConnectionId,
            CommunicationProvider::from($recipient->provider),
            $recipient->address,
            $body,
            ['permanentPlaceOfferId' => $offer->id()->toRfc4122()],
            metadata: ['source' => 'permanent-place-offer-cancelled'],
            dedupeKey: 'permanent-place-offer-cancelled:'.$offer->id()->toRfc4122(),
        );
    }

    private function cancelUnavailable(PermanentPlaceOffer $offer, \DateTimeImmutable $now): void
    {
        $offer->cancel('PLACE_UNAVAILABLE', $now);
        $this->allocations->releaseForOffer($offer->id());
        $this->offers->save($offer);
        $this->queueCancellation($offer, 'Предложенное постоянное место больше недоступно.');
    }

    private static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }
}
