<?php

declare(strict_types=1);

namespace App\Module\Waiting\Application;

use App\Module\Catalog\Application\ServiceCatalog;
use App\Module\Clients\Application\ClientDirectory;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Waiting\Domain\Model\WaitingListAvailability;
use App\Module\Waiting\Domain\Model\WaitingListEntry;
use App\Module\Workforce\Application\WorkforceService;
use Symfony\Component\Uid\Ulid;

final readonly class WaitingListManager
{
    public function __construct(
        private WaitingListStore $store,
        private ClientDirectory $clients,
        private ServiceCatalog $services,
        private WorkforceService $workforce,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function activeForClient(string $clientId): ?array
    {
        $client = $this->clients->find($clientId);
        $entry = $this->store->findActiveForClient($client->id());

        return null === $entry ? null : $this->present($entry);
    }

    /** @param list<array{weekday: int, startTime: string, endTime: ?string}> $availability
     *  @return array<string, mixed>
     */
    public function save(AdministratorAccount $actor, string $clientId, string $serviceId, ?string $specialistId, int $requiredFrequency, bool $readyForOneOff, ?string $comment, array $availability, \DateTimeImmutable $now): array
    {
        $client = $this->clients->find($clientId);
        $service = $this->services->find($serviceId);
        $specialist = null === $specialistId ? null : $this->workforce->find($specialistId);
        if ([] === $availability || 7 < count($availability)) {
            throw new \InvalidArgumentException('Добавьте хотя бы один подходящий день.');
        }
        $weekdays = [];
        foreach ($availability as $interval) {
            if (isset($weekdays[$interval['weekday']])) {
                throw new \InvalidArgumentException('Каждый день недели можно указать только один раз.');
            }
            $weekdays[$interval['weekday']] = true;
        }

        $entry = $this->store->findActiveForClient($client->id())
            ?? WaitingListEntry::create(
                $client,
                $service->id(),
                $specialist?->id(),
                $requiredFrequency,
                $readyForOneOff,
                new \DateTimeImmutable($now->setTimezone(new \DateTimeZone($actor->organization()->timezone()))->format('Y-m-d'), new \DateTimeZone('UTC')),
                $comment,
                $now,
            );
        $entry->change($service->id(), $specialist?->id(), $requiredFrequency, $readyForOneOff, $comment, $now);
        $days = array_map(static fn (array $interval): WaitingListAvailability => WaitingListAvailability::create(
            $entry,
            $interval['weekday'],
            $interval['startTime'],
            $interval['endTime'],
        ), $availability);

        $this->store->transactional(fn () => $this->store->replaceAvailability($entry, ...$days));

        return $this->present($entry, $days);
    }

    public function end(AdministratorAccount $actor, string $clientId, \DateTimeImmutable $now): void
    {
        $client = $this->clients->find($clientId);
        $entry = $this->store->findActiveForClient($client->id()) ?? throw new \OutOfBoundsException('Активное ожидание не найдено.');
        $entry->end($now);
        $this->store->save($entry);
    }

    /** @param list<WaitingListAvailability>|null $availability
     *  @return array<string, mixed>
     */
    public function present(WaitingListEntry $entry, ?array $availability = null): array
    {
        $service = $this->services->reference($entry->serviceId()->toRfc4122());
        $specialist = null === $entry->specialistId() ? null : $this->workforce->find($entry->specialistId()->toRfc4122());

        return [
            'id' => $entry->id()->toRfc4122(),
            'clientId' => $entry->clientId()->toRfc4122(),
            'service' => $service,
            'specialist' => null === $specialist ? null : ['id' => $specialist->id()->toRfc4122(), 'name' => $specialist->name()],
            'requiredFrequency' => $entry->requiredFrequency(),
            'readyForOneOff' => $entry->readyForOneOff(),
            'effectiveFrom' => $entry->effectiveFrom()->format('Y-m-d'),
            'comment' => $entry->comment(),
            'availability' => array_map(static fn (WaitingListAvailability $day): array => [
                'weekday' => $day->weekday(),
                'startTime' => $day->startTime(),
                'endTime' => $day->endTime(),
            ], $availability ?? $this->store->availability($entry->id())),
        ];
    }
}
