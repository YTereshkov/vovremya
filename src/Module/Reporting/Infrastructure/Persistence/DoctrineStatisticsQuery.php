<?php

declare(strict_types=1);

namespace App\Module\Reporting\Infrastructure\Persistence;

use App\Module\Organization\Application\OrganizationContext;
use App\Module\Reporting\Application\StatisticsQuery;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineStatisticsQuery implements StatisticsQuery
{
    public function __construct(private Connection $connection, private OrganizationContext $context)
    {
    }

    public function summary(?string $month, ?Ulid $specialistId): array
    {
        $organizationId = $this->context->currentId()->toRfc4122();
        $timezone = (string) $this->connection->fetchOne(
            'SELECT timezone FROM organizations WHERE id = :organization',
            ['organization' => $organizationId],
        );
        $period = $this->period($month, $timezone);
        $specialists = $this->connection->fetchAllAssociative(
            'SELECT id, name FROM specialists WHERE organization_id = :organization ORDER BY name, id',
            ['organization' => $organizationId],
        );
        $specialist = $specialistId?->toRfc4122();
        if (null !== $specialist && !in_array($specialist, array_column($specialists, 'id'), true)) {
            throw new \OutOfBoundsException('Specialist not found.');
        }

        $parameters = [
            'organization' => $organizationId,
            'from' => $period['from']->format('Y-m-d H:i:sP'),
            'to' => $period['to']->format('Y-m-d H:i:sP'),
            'specialist' => $specialist,
        ];
        $appointments = $this->connection->fetchAssociative(<<<'SQL'
            SELECT
                COUNT(*) FILTER (WHERE planning_status = 'PLANNED' AND result_status IS DISTINCT FROM 'RESCHEDULED') AS planned,
                COUNT(*) FILTER (WHERE result_status = 'CONDUCTED') AS conducted,
                COUNT(*) FILTER (WHERE result_status = 'CANCELLED_BY_CLIENT' AND result_is_late = FALSE) AS cancelled_client_early,
                COUNT(*) FILTER (WHERE result_status = 'CANCELLED_BY_CLIENT' AND result_is_late = TRUE) AS cancelled_client_late,
                COUNT(*) FILTER (WHERE result_status = 'CANCELLED_BY_SPECIALIST') AS cancelled_specialist,
                COUNT(*) FILTER (WHERE result_status = 'NO_SHOW') AS no_shows
            FROM appointments
            WHERE organization_id = :organization AND starts_at >= :from AND starts_at < :to
              AND (CAST(:specialist AS UUID) IS NULL OR specialist_id = CAST(:specialist AS UUID))
            SQL, $parameters);
        $transfers = $this->connection->fetchAssociative(<<<'SQL'
            SELECT COUNT(*) AS requested,
                   COUNT(*) FILTER (WHERE requests.status = 'COMPLETED') AS completed
            FROM transfer_requests requests
            INNER JOIN appointments
                ON appointments.organization_id = requests.organization_id AND appointments.id = requests.appointment_id
            WHERE requests.organization_id = :organization AND appointments.starts_at >= :from AND appointments.starts_at < :to
              AND (CAST(:specialist AS UUID) IS NULL OR appointments.specialist_id = CAST(:specialist AS UUID))
            SQL, $parameters);
        $confirmations = $this->connection->fetchAssociative(<<<'SQL'
            SELECT
                COUNT(*) FILTER (WHERE requests.status = 'CONFIRMED') AS confirmed,
                COUNT(*) FILTER (WHERE requests.status = 'NO_RESPONSE') AS no_response,
                COUNT(*) FILTER (WHERE appointments.result_status = 'NO_SHOW' AND requests.status = 'CONFIRMED') AS no_show_confirmed,
                COUNT(*) FILTER (WHERE appointments.result_status = 'NO_SHOW' AND requests.status IS DISTINCT FROM 'CONFIRMED') AS no_show_unconfirmed
            FROM appointments
            LEFT JOIN appointment_confirmation_requests requests
                ON requests.organization_id = appointments.organization_id AND requests.appointment_id = appointments.id
            WHERE appointments.organization_id = :organization AND appointments.starts_at >= :from AND appointments.starts_at < :to
              AND (CAST(:specialist AS UUID) IS NULL OR appointments.specialist_id = CAST(:specialist AS UUID))
            SQL, $parameters);
        $waiting = $this->connection->fetchAssociative(<<<'SQL'
            SELECT COUNT(DISTINCT windows.id) AS free_windows,
                   COUNT(DISTINCT offers.free_window_id) FILTER (
                       WHERE offers.status = 'ACCEPTED' AND offers.target_type = 'WAITING_LIST'
                   ) AS filled_from_waiting
            FROM free_windows windows
            LEFT JOIN free_window_offers offers
                ON offers.organization_id = windows.organization_id AND offers.free_window_id = windows.id
            WHERE windows.organization_id = :organization AND windows.starts_at >= :from AND windows.starts_at < :to
              AND (CAST(:specialist AS UUID) IS NULL OR windows.specialist_id = CAST(:specialist AS UUID))
            SQL, $parameters);

        return [
            'month' => $period['from']->setTimezone(new \DateTimeZone($timezone))->format('Y-m'),
            'timezone' => $timezone,
            'selectedSpecialistId' => $specialist,
            'specialists' => array_map(static fn (array $row): array => ['id' => $row['id'], 'name' => $row['name']], $specialists),
            'appointments' => [
                'planned' => self::integer($appointments, 'planned'),
                'conducted' => self::integer($appointments, 'conducted'),
                'cancelledByClientEarly' => self::integer($appointments, 'cancelled_client_early'),
                'cancelledByClientLate' => self::integer($appointments, 'cancelled_client_late'),
                'cancelledBySpecialist' => self::integer($appointments, 'cancelled_specialist'),
                'noShows' => self::integer($appointments, 'no_shows'),
            ],
            'scheduleChanges' => [
                'transferRequests' => self::integer($transfers, 'requested'),
                'successfulTransfers' => self::integer($transfers, 'completed'),
            ],
            'confirmations' => [
                'confirmed' => self::integer($confirmations, 'confirmed'),
                'noResponse' => self::integer($confirmations, 'no_response'),
                'noShowsAfterConfirmation' => self::integer($confirmations, 'no_show_confirmed'),
                'noShowsWithoutConfirmation' => self::integer($confirmations, 'no_show_unconfirmed'),
            ],
            'waitingList' => [
                'freeWindows' => self::integer($waiting, 'free_windows'),
                'filledFromWaiting' => self::integer($waiting, 'filled_from_waiting'),
            ],
        ];
    }

    /** @return array{from: \DateTimeImmutable, to: \DateTimeImmutable} */
    private function period(?string $month, string $timezone): array
    {
        $zone = new \DateTimeZone($timezone);
        $value = $month ?? new \DateTimeImmutable('now', $zone)->format('Y-m');
        if (1 !== preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            throw new \InvalidArgumentException('Invalid statistics month.');
        }
        $from = new \DateTimeImmutable($value.'-01 00:00:00', $zone);

        return ['from' => $from->setTimezone(new \DateTimeZone('UTC')), 'to' => $from->modify('+1 month')->setTimezone(new \DateTimeZone('UTC'))];
    }

    /** @param array<string, mixed>|false $row */
    private static function integer(array|false $row, string $key): int
    {
        return false === $row ? 0 : (int) $row[$key];
    }
}
