<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Infrastructure\Persistence;

use App\Module\Organization\Application\OrganizationContext;
use App\Module\Scheduling\Application\CalendarAppointment;
use App\Module\Scheduling\Application\CalendarQuery;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineCalendarQuery implements CalendarQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrganizationContext $organizationContext,
    ) {
    }

    public function between(\DateTimeImmutable $from, \DateTimeImmutable $until, ?Ulid $specialistId = null): array
    {
        $parameters = [
            'organization_id' => $this->organizationContext->currentId()->toRfc4122(),
            'from' => $from->format(\DateTimeInterface::RFC3339_EXTENDED),
            'until' => $until->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
        $specialistFilter = '';
        if (null !== $specialistId) {
            $specialistFilter = ' AND a.specialist_id = :specialist_id';
            $parameters['specialist_id'] = $specialistId->toRfc4122();
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            $this->selectSql().' '.<<<SQL
                WHERE a.organization_id = :organization_id
                  AND a.planning_status = 'PLANNED'
                  AND a.starts_at >= CAST(:from AS TIMESTAMPTZ)
                  AND a.starts_at < CAST(:until AS TIMESTAMPTZ)
                {$specialistFilter}
                ORDER BY a.starts_at, s.name, a.id
                SQL,
            $parameters,
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function find(Ulid $appointmentId): ?CalendarAppointment
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            $this->selectSql().' '.<<<'SQL'
                WHERE a.organization_id = :organization_id
                  AND a.id = :appointment_id
                SQL,
            [
                'organization_id' => $this->organizationContext->currentId()->toRfc4122(),
                'appointment_id' => $appointmentId->toRfc4122(),
            ],
        );

        return false === $row ? null : $this->hydrate($row);
    }

    private function selectSql(): string
    {
        return <<<'SQL'
            SELECT
                a.id,
                a.specialist_id,
                s.name AS specialist_name,
                s.specialization AS specialist_specialization,
                a.client_id,
                c.name AS client_name,
                a.service_id,
                a.service_name_snapshot,
                a.service_default_duration_snapshot,
                a.service_minimum_duration_snapshot,
                a.service_maximum_duration_snapshot,
                a.duration_minutes,
                a.starts_at,
                a.ends_at,
                COALESCE(confirmation.status, 'NOT_REQUESTED') AS confirmation_status,
                a.result_status,
                a.result_recorded_at,
                a.result_is_late,
                a.result_respectful_reason,
                a.result_comment
            FROM appointments a
            INNER JOIN specialists s
                ON s.organization_id = a.organization_id AND s.id = a.specialist_id
            INNER JOIN clients c
                ON c.organization_id = a.organization_id AND c.id = a.client_id
            LEFT JOIN appointment_confirmation_requests confirmation
                ON confirmation.organization_id = a.organization_id AND confirmation.appointment_id = a.id
            SQL;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): CalendarAppointment
    {
        return new CalendarAppointment(
            Ulid::fromString((string) $row['id']),
            Ulid::fromString((string) $row['specialist_id']),
            (string) $row['specialist_name'],
            (string) $row['specialist_specialization'],
            Ulid::fromString((string) $row['client_id']),
            (string) $row['client_name'],
            Ulid::fromString((string) $row['service_id']),
            (string) $row['service_name_snapshot'],
            (int) $row['service_default_duration_snapshot'],
            null === $row['service_minimum_duration_snapshot'] ? null : (int) $row['service_minimum_duration_snapshot'],
            null === $row['service_maximum_duration_snapshot'] ? null : (int) $row['service_maximum_duration_snapshot'],
            (int) $row['duration_minutes'],
            new \DateTimeImmutable((string) $row['starts_at']),
            new \DateTimeImmutable((string) $row['ends_at']),
            (string) $row['confirmation_status'],
            null === $row['result_status'] ? null : (string) $row['result_status'],
            null === $row['result_recorded_at'] ? null : new \DateTimeImmutable((string) $row['result_recorded_at']),
            null === $row['result_is_late'] ? null : (bool) $row['result_is_late'],
            (bool) $row['result_respectful_reason'],
            null === $row['result_comment'] ? null : (string) $row['result_comment'],
        );
    }
}
