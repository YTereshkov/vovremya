<?php

declare(strict_types=1);

namespace App\Module\Clients\Application;

use App\Module\Clients\Domain\Model\Client;
use App\Module\Clients\Domain\Model\ClientAbsence;
use App\Module\Clients\Domain\Model\ClientAbsenceMode;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Scheduling\Application\AppointmentAbsenceService;
use App\Module\Scheduling\Application\RegularScheduleManager;

final readonly class ClientAbsenceService
{
    public function __construct(
        private ClientStore $store,
        private AppointmentAbsenceService $appointments,
        private RegularScheduleManager $regularSchedules,
    ) {
    }

    public function impact(Client $client, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn): int
    {
        return $this->appointments->clientImpact($client->organization(), $client->id(), $startsOn, $endsOn);
    }

    /** @return array{absence: ClientAbsence, appointments: int, freeWindows: int, notifications: int, regularSchedulesEnded: int} */
    public function create(
        AdministratorAccount $actor,
        Client $client,
        \DateTimeImmutable $startsOn,
        \DateTimeImmutable $endsOn,
        ?string $reason,
        ClientAbsenceMode $mode,
        bool $createFreeWindows,
        bool $notifyClient,
        \DateTimeImmutable $now,
    ): array {
        $absence = ClientAbsence::create($client, $startsOn, $endsOn, $reason, $mode, $createFreeWindows, $notifyClient, $now);

        return $this->store->transactional(function () use ($actor, $client, $absence, $startsOn, $endsOn, $reason, $mode, $createFreeWindows, $notifyClient, $now): array {
            $this->store->save($absence);
            $releasePlace = ClientAbsenceMode::ReleasePermanentPlace === $mode;
            $result = $this->appointments->cancelForClientAbsence(
                $actor,
                $absence->id(),
                $client->id(),
                $startsOn,
                $endsOn,
                $reason,
                $createFreeWindows,
                $notifyClient,
                $releasePlace,
                $now,
            );
            $ended = $releasePlace ? $this->regularSchedules->endForClient($client->id(), $startsOn) : 0;

            return ['absence' => $absence, ...$result, 'regularSchedulesEnded' => $ended];
        });
    }
}
