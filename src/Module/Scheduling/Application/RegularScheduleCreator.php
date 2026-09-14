<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Catalog\Application\ServiceStore;
use App\Module\Catalog\Domain\Model\Service;
use App\Module\Clients\Application\ClientStore;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Domain\Model\Organization;
use App\Module\Scheduling\Domain\Model\RegularSchedule;
use App\Module\Scheduling\Domain\Model\RegularScheduleDay;
use App\Module\Scheduling\Domain\RegularScheduleConflicts;
use App\Module\Workforce\Application\WorkforceStore;
use App\Module\Workforce\Domain\Model\Specialist;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use Symfony\Component\Uid\Ulid;

final readonly class RegularScheduleCreator
{
    public function __construct(
        private RegularScheduleStore $schedules,
        private RegularScheduleMaterializer $materializer,
        private AvailabilityService $availability,
        private WorkforceStore $workforce,
        private ClientStore $clients,
        private ServiceStore $services,
        private ScheduleAllocationStore $allocations,
    ) {
    }

    /** @param list<array{weekday: int, startTime: string, durationMinutes?: int|null}> $rules */
    public function create(
        AdministratorAccount $actor,
        string $specialistId,
        string $clientId,
        string $serviceId,
        string $startsOn,
        ?string $endsOn,
        array $rules,
    ): RegularSchedule {
        $specialist = $this->workforce->find($this->id($specialistId, 'Специалист не найден.')) ?? throw new \OutOfBoundsException('Специалист не найден.');
        $client = $this->clients->find($this->id($clientId, 'Клиент не найден.')) ?? throw new \OutOfBoundsException('Клиент не найден.');
        $service = $this->services->findActive($this->id($serviceId, 'Услуга не найдена.')) ?? throw new \OutOfBoundsException('Услуга не найдена.');
        if (!OrganizationIsolation::belongsTo($actor->organizationId(), $specialist, $client, $service)) {
            throw new \LogicException('Cannot create a regular schedule across organizations.');
        }
        $timezone = new \DateTimeZone($actor->organization()->timezone());
        $start = $this->date($startsOn, $timezone);
        $end = null === $endsOn || '' === $endsOn ? null : $this->date($endsOn, $timezone);
        $today = new \DateTimeImmutable('today', $timezone);
        if ($start < $today) {
            throw new \InvalidArgumentException('Дата начала не может быть в прошлом.');
        }
        if ([] === $rules || 7 < count($rules)) {
            throw new \InvalidArgumentException('Добавьте от одного до семи дней расписания.');
        }

        return $this->createResolved($actor->organization(), $specialist, $client, $service, $start, $end, $rules, $today);
    }

    /** @param list<array{weekday: int, startTime: string, durationMinutes?: int|null}> $rules */
    public function createFromPermanentPlace(
        Organization $organization,
        Ulid $offerId,
        Ulid $specialistId,
        Ulid $clientId,
        Ulid $serviceId,
        \DateTimeImmutable $availableFrom,
        array $rules,
        \DateTimeImmutable $now,
    ): RegularSchedule {
        $specialist = $this->workforce->find($specialistId) ?? throw new \OutOfBoundsException('Специалист не найден.');
        $client = $this->clients->find($clientId) ?? throw new \OutOfBoundsException('Клиент не найден.');
        $service = $this->services->findActive($serviceId) ?? throw new \OutOfBoundsException('Услуга не найдена.');
        if (!OrganizationIsolation::belongsTo($organization->id(), $specialist, $client, $service)) {
            throw new \LogicException('Cannot create a regular schedule across organizations.');
        }
        $timezone = new \DateTimeZone($organization->timezone());
        $localNow = $now->setTimezone($timezone);
        $today = new \DateTimeImmutable($localNow->format('Y-m-d'), $timezone);
        $start = new \DateTimeImmutable(max($today->format('Y-m-d'), $availableFrom->format('Y-m-d')), $timezone);
        foreach ($rules as $rule) {
            if ($start == $today && ($rule['weekday'] ?? null) === (int) $today->format('N') && is_string($rule['startTime'] ?? null) && $rule['startTime'] <= $localNow->format('H:i')) {
                $start = $start->modify('+1 day');
                break;
            }
        }

        return $this->createResolved($organization, $specialist, $client, $service, $start, null, $rules, $today, $offerId);
    }

    /** @param list<array{weekday: int, startTime: string, durationMinutes?: int|null}> $rules */
    private function createResolved(
        Organization $organization,
        Specialist $specialist,
        Client $client,
        Service $service,
        \DateTimeImmutable $start,
        ?\DateTimeImmutable $end,
        array $rules,
        \DateTimeImmutable $today,
        ?Ulid $excludeOfferId = null,
    ): RegularSchedule {
        if ([] === $rules || 7 < count($rules)) {
            throw new \InvalidArgumentException('Добавьте от одного до семи дней расписания.');
        }
        $schedule = RegularSchedule::create($organization, $specialist, $client, $service, $start, $end);
        $days = [];
        $weekdays = [];
        foreach ($rules as $rule) {
            if (!is_int($rule['weekday'] ?? null) || !is_string($rule['startTime'] ?? null)) {
                throw new \InvalidArgumentException('Некорректные дни регулярного расписания.');
            }
            if (isset($weekdays[$rule['weekday']])) {
                throw new \InvalidArgumentException('Один день недели нельзя добавить дважды.');
            }
            $weekdays[$rule['weekday']] = true;
            $duration = $rule['durationMinutes'] ?? $service->defaultDurationMinutes();
            if (!is_int($duration)) {
                throw new \InvalidArgumentException('Длительность должна быть целым числом минут.');
            }
            $days[] = RegularScheduleDay::create($schedule, $rule['weekday'], $rule['startTime'], $duration, $start);
        }

        $conflicts = [];
        foreach ($this->materializer->occurrences($schedule, $days, $today) as $occurrence) {
            $decision = $this->availability->check($specialist->id(), $occurrence->startsAt, $occurrence->endsAt, null, $excludeOfferId);
            if (!$decision->available) {
                $conflict = $decision->conflict ?? throw new \LogicException('Missing availability conflict.');
                $conflicts[] = new RegularScheduleConflict($occurrence->date->format('Y-m-d'), $occurrence->day->startTime(), $conflict->code, $conflict->message);
            }
        }
        if ([] !== $conflicts) {
            throw new RegularScheduleConflicts($conflicts);
        }

        if (null !== $excludeOfferId) {
            $this->schedules->transactional(function () use ($schedule, $days, $excludeOfferId, $today): void {
                $this->schedules->save($schedule, ...$days);
                $this->allocations->releaseForOffer($excludeOfferId);
                $this->materializer->materialize($schedule, $today);
            });
        } else {
            $this->schedules->transactional(fn () => $this->schedules->save($schedule, ...$days));
            $this->materializer->materialize($schedule, $today);
        }

        return $schedule;
    }

    private function id(string $value, string $message): Ulid
    {
        try {
            return Ulid::fromString($value);
        } catch (\InvalidArgumentException) {
            throw new \OutOfBoundsException($message);
        }
    }

    private function date(string $value, \DateTimeZone $timezone): \DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            throw new \InvalidArgumentException('Укажите дату в формате YYYY-MM-DD.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Укажите корректную дату.');
        }

        return $date;
    }
}
