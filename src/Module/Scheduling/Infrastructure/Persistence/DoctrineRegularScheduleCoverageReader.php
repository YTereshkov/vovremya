<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Infrastructure\Persistence;

use App\Module\Organization\Application\OrganizationContext;
use App\Module\Scheduling\Application\RegularScheduleCoverageReader;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineRegularScheduleCoverageReader implements RegularScheduleCoverageReader
{
    public function __construct(private Connection $connection, private OrganizationContext $context)
    {
    }

    public function weeklyFrequency(array $clientIds, Ulid $serviceId, \DateTimeImmutable $date): array
    {
        if ([] === $clientIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT schedule.client_id, COUNT(day.id) AS frequency
                FROM regular_schedules schedule
                INNER JOIN regular_schedule_days day
                    ON day.organization_id = schedule.organization_id
                   AND day.regular_schedule_id = schedule.id
                WHERE schedule.organization_id = :organization
                  AND schedule.client_id IN (:clients)
                  AND schedule.service_id = :service
                  AND schedule.starts_on <= :date
                  AND (schedule.inactive_from IS NULL OR schedule.inactive_from > :date)
                  AND day.active_from <= :date
                  AND (day.inactive_from IS NULL OR day.inactive_from > :date)
                GROUP BY schedule.client_id
                SQL,
            [
                'organization' => $this->context->currentId()->toRfc4122(),
                'clients' => array_map(static fn (Ulid $id): string => $id->toRfc4122(), $clientIds),
                'service' => $serviceId->toRfc4122(),
                'date' => $date->format('Y-m-d'),
            ],
            ['clients' => ArrayParameterType::STRING],
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['client_id']] = (int) $row['frequency'];
        }

        return $result;
    }
}
