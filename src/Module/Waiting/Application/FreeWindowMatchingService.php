<?php

declare(strict_types=1);

namespace App\Module\Waiting\Application;

use App\Module\Catalog\Application\ServiceCatalog;
use App\Module\Clients\Application\WaitingClientReader;
use App\Module\Organization\Domain\Model\Organization;
use App\Module\Scheduling\Application\AvailabilityService;
use App\Module\Scheduling\Application\EarlierAppointmentCandidate;
use App\Module\Scheduling\Application\EarlierAppointmentCandidateSource;
use App\Module\Waiting\Domain\Model\FreeWindow;
use App\Module\Waiting\Domain\Model\FreeWindowStatus;
use App\Module\Waiting\Domain\Model\WaitingListEntry;
use Symfony\Component\Uid\Ulid;

final readonly class FreeWindowMatchingService
{
    public function __construct(
        private FreeWindowStore $windows,
        private WaitingListStore $waiting,
        private EarlierAppointmentCandidateSource $appointments,
        private WaitingClientReader $clients,
        private ServiceCatalog $services,
        private AvailabilityService $availability,
    ) {
    }

    /** @return array{moveEarlier: list<array<string, mixed>>, waitingClients: list<array<string, mixed>>} */
    public function candidates(string $windowId, Organization $organization, \DateTimeImmutable $now): array
    {
        try {
            $id = Ulid::fromString($windowId);
        } catch (\InvalidArgumentException) {
            throw new \OutOfBoundsException('Свободное окно не найдено.');
        }
        $window = $this->windows->find($id) ?? throw new \OutOfBoundsException('Свободное окно не найдено.');
        if (FreeWindowStatus::Open !== $window->status() || $window->startsAt() <= $now) {
            throw new \OutOfBoundsException('Свободное окно не найдено.');
        }
        if (!$this->availability->check($window->specialistId(), $window->startsAt(), $window->endsAt())->available) {
            return ['moveEarlier' => [], 'waitingClients' => []];
        }

        $zone = new \DateTimeZone($organization->timezone());
        $localStart = $window->startsAt()->setTimezone($zone);
        $localDate = new \DateTimeImmutable($localStart->format('Y-m-d'), new \DateTimeZone('UTC'));
        $nextDay = new \DateTimeImmutable($localStart->modify('+1 day')->format('Y-m-d').' 00:00', $zone);
        $later = array_values(array_filter(
            $this->appointments->laterAppointments($window->specialistId(), $window->serviceId(), $window->startsAt(), $nextDay),
            fn (EarlierAppointmentCandidate $candidate): bool => $window->startsAt()->modify(sprintf('+%d minutes', $candidate->durationMinutes)) <= $window->endsAt()
                && $this->availability->check(
                    $window->specialistId(),
                    $window->startsAt(),
                    $window->startsAt()->modify(sprintf('+%d minutes', $candidate->durationMinutes)),
                    $candidate->appointmentId,
                )->available,
        ));
        $laterProfiles = $this->clients->availableProfiles(array_map(static fn (EarlierAppointmentCandidate $candidate): Ulid => $candidate->clientId, $later), $localDate);
        $moveEarlier = [];
        $excludedClients = [];
        foreach ($later as $candidate) {
            $key = $candidate->clientId->toRfc4122();
            if (!isset($laterProfiles[$key])) {
                continue;
            }
            $excludedClients[$key] = true;
            $moveEarlier[] = [
                'appointmentId' => $candidate->appointmentId->toRfc4122(),
                'client' => ['id' => $key, 'name' => $laterProfiles[$key]->name],
                'currentStartsAt' => $candidate->currentStartsAt->format(\DateTimeInterface::RFC3339_EXTENDED),
                'proposedStartsAt' => $window->startsAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
            ];
        }

        try {
            $service = $this->services->find($window->serviceId()->toRfc4122());
        } catch (\OutOfBoundsException) {
            return ['moveEarlier' => $moveEarlier, 'waitingClients' => []];
        }
        $candidateEnd = $window->startsAt()->modify(sprintf('+%d minutes', $service->defaultDurationMinutes()));
        if ($candidateEnd > $window->endsAt()) {
            return ['moveEarlier' => $moveEarlier, 'waitingClients' => []];
        }
        $entries = $this->waiting->matchingOneOff(
            $window->serviceId(),
            $window->specialistId(),
            $localDate,
            (int) $localStart->format('N'),
            $localStart->format('H:i'),
            $candidateEnd->setTimezone($zone)->format('H:i'),
        );
        $profiles = $this->clients->availableProfiles(array_map(static fn (WaitingListEntry $entry): Ulid => $entry->clientId(), $entries), $localDate);
        $waitingClients = [];
        foreach ($entries as $entry) {
            $key = $entry->clientId()->toRfc4122();
            if (isset($excludedClients[$key]) || !isset($profiles[$key])) {
                continue;
            }
            $waitingClients[] = [
                'waitingListEntryId' => $entry->id()->toRfc4122(),
                'client' => ['id' => $key, 'name' => $profiles[$key]->name],
                'availability' => $this->availabilityLabel($entry),
            ];
        }

        return ['moveEarlier' => $moveEarlier, 'waitingClients' => $waitingClients];
    }

    private function availabilityLabel(WaitingListEntry $entry): string
    {
        $labels = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];

        return implode(' · ', array_map(static fn ($day): string => sprintf(
            '%s %s',
            $labels[$day->weekday()],
            null === $day->endTime() ? 'после '.$day->startTime() : $day->startTime().'–'.$day->endTime(),
        ), $this->waiting->availability($entry->id())));
    }
}
