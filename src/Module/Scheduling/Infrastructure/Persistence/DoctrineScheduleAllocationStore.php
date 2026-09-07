<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Infrastructure\Persistence;

use App\Module\Organization\Application\OrganizationContext;
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

    public function save(ScheduleAllocation $allocation): void
    {
        if (!OrganizationIsolation::belongsTo($this->organizationContext->currentId(), $allocation)) {
            throw new \LogicException('Cannot write outside the current organization.');
        }

        try {
            $this->entityManager->persist($allocation);
            $this->entityManager->flush();
        } catch (DriverException $exception) {
            if ('23P01' === $exception->getSQLState()) {
                throw new TimeUnavailable(AvailabilityConflict::timeAlreadyUnavailable(), $exception);
            }

            throw $exception;
        }
    }
}
