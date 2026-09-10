<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Infrastructure\Persistence;

use App\Module\Organization\Application\OrganizationContext;
use App\Module\Scheduling\Application\AllocationInterval;
use App\Module\Scheduling\Application\ScheduleAllocationStore;
use App\Module\Scheduling\Domain\AvailabilityConflict;
use App\Module\Scheduling\Domain\Model\ScheduleAllocation;
use App\Module\Scheduling\Domain\TimeUnavailable;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineScheduleAllocationStore implements ScheduleAllocationStore
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrganizationContext $organizationContext,
    ) {
    }

    public function hasActiveConflict(Ulid $specialistId, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): bool
    {
        $conflict = $this->entityManager->getConnection()->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM schedule_allocations
                    WHERE organization_id = :organization_id
                      AND specialist_id = :specialist_id
                      AND released_at IS NULL
                      AND tstzrange(starts_at, ends_at, '[)')
                          && tstzrange(CAST(:starts_at AS TIMESTAMPTZ), CAST(:ends_at AS TIMESTAMPTZ), '[)')
                )
                SQL,
            [
                'organization_id' => $this->organizationContext->currentId()->toRfc4122(),
                'specialist_id' => $specialistId->toRfc4122(),
                'starts_at' => $startsAt->format(\DateTimeInterface::RFC3339_EXTENDED),
                'ends_at' => $endsAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            ],
        );

        return true === $conflict || '1' === $conflict;
    }

    public function activeNear(Ulid $specialistId, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): array
    {
        $windowStart = $startsAt->modify('-15 minutes');
        $windowEnd = $endsAt->modify('+15 minutes');
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT starts_at, ends_at
                FROM schedule_allocations
                WHERE organization_id = :organization_id
                  AND specialist_id = :specialist_id
                  AND released_at IS NULL
                  AND starts_at < CAST(:window_end AS TIMESTAMPTZ)
                  AND ends_at > CAST(:window_start AS TIMESTAMPTZ)
                ORDER BY starts_at, ends_at
                SQL,
            [
                'organization_id' => $this->organizationContext->currentId()->toRfc4122(),
                'specialist_id' => $specialistId->toRfc4122(),
                'window_start' => $windowStart->format(\DateTimeInterface::RFC3339_EXTENDED),
                'window_end' => $windowEnd->format(\DateTimeInterface::RFC3339_EXTENDED),
            ],
        );

        return array_map(static fn (array $row): AllocationInterval => new AllocationInterval(
            new \DateTimeImmutable((string) $row['starts_at']),
            new \DateTimeImmutable((string) $row['ends_at']),
        ), $rows);
    }

    public function save(ScheduleAllocation $allocation): void
    {
        if (!OrganizationIsolation::belongsTo($this->organizationContext->currentId(), $allocation)) {
            throw new \LogicException('Cannot write outside the current organization.');
        }

        try {
            $this->entityManager->getConnection()->executeStatement(
                <<<'SQL'
                    INSERT INTO schedule_allocations (
                        id, organization_id, specialist_id, source_id, allocation_type,
                        starts_at, ends_at, released_at, created_at
                    ) VALUES (
                        :id, :organization_id, :specialist_id, :source_id, :allocation_type,
                        :starts_at, :ends_at, :released_at, CURRENT_TIMESTAMP
                    )
                    ON CONFLICT (id) DO UPDATE SET released_at = EXCLUDED.released_at
                    SQL,
                [
                    'id' => $allocation->id()->toRfc4122(),
                    'organization_id' => $allocation->organizationId()->toRfc4122(),
                    'specialist_id' => $allocation->specialistId()->toRfc4122(),
                    'source_id' => $allocation->sourceId()->toRfc4122(),
                    'allocation_type' => $allocation->type()->value,
                    'starts_at' => $allocation->startsAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
                    'ends_at' => $allocation->endsAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
                    'released_at' => $allocation->releasedAt()?->format(\DateTimeInterface::RFC3339_EXTENDED),
                ],
            );
        } catch (DriverException $exception) {
            if ('23P01' === $exception->getSQLState()) {
                throw new TimeUnavailable(AvailabilityConflict::timeAlreadyUnavailable(), $exception);
            }

            throw $exception;
        }
    }

    public function releaseForAppointment(Ulid $appointmentId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE schedule_allocations
                SET released_at = CURRENT_TIMESTAMP
                WHERE organization_id = :organization_id
                  AND allocation_type = 'APPOINTMENT'
                  AND source_id = :appointment_id
                  AND released_at IS NULL
                SQL,
            [
                'organization_id' => $this->organizationContext->currentId()->toRfc4122(),
                'appointment_id' => $appointmentId->toRfc4122(),
            ],
        );
    }

    public function restoreForAppointment(Ulid $appointmentId): void
    {
        try {
            $this->entityManager->getConnection()->executeStatement(
                <<<'SQL'
                    UPDATE schedule_allocations
                    SET released_at = NULL
                    WHERE organization_id = :organization_id
                      AND allocation_type = 'APPOINTMENT'
                      AND source_id = :appointment_id
                      AND released_at IS NOT NULL
                    SQL,
                [
                    'organization_id' => $this->organizationContext->currentId()->toRfc4122(),
                    'appointment_id' => $appointmentId->toRfc4122(),
                ],
            );
        } catch (DriverException $exception) {
            if ('23P01' === $exception->getSQLState()) {
                throw new TimeUnavailable(AvailabilityConflict::timeAlreadyUnavailable(), $exception);
            }

            throw $exception;
        }
    }
}
