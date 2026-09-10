<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Infrastructure\Persistence;

use App\Module\Organization\Application\OrganizationContext;
use App\Module\Scheduling\Application\RegularScheduleStore;
use App\Module\Scheduling\Domain\Model\RegularSchedule;
use App\Module\Scheduling\Domain\Model\RegularScheduleDay;
use App\Module\Scheduling\Domain\Model\ScheduleGenerationIssue;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineRegularScheduleStore implements RegularScheduleStore
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrganizationContext $organizationContext,
    ) {
    }

    public function all(): array
    {
        return $this->query(RegularSchedule::class)
            ->orderBy('item.startsOn', 'DESC')->addOrderBy('item.id', 'ASC')
            ->getQuery()->getResult();
    }

    public function find(Ulid $id): ?RegularSchedule
    {
        return $this->query(RegularSchedule::class)
            ->andWhere('item.id = :id')->setParameter('id', $id, 'ulid')
            ->getQuery()->getOneOrNullResult();
    }

    public function activeForClient(Ulid $clientId, \DateTimeImmutable $date): array
    {
        return $this->query(RegularSchedule::class)
            ->andWhere('item.clientId = :client')->setParameter('client', $clientId, 'ulid')
            ->andWhere('item.startsOn <= :date')->setParameter('date', $date, 'date_immutable')
            ->andWhere('(item.inactiveFrom IS NULL OR item.inactiveFrom > :date)')
            ->orderBy('item.startsOn', 'ASC')->addOrderBy('item.id', 'ASC')
            ->getQuery()->getResult();
    }

    public function days(Ulid $scheduleId, bool $includeInactive = false): array
    {
        $query = $this->query(RegularScheduleDay::class)
            ->andWhere('item.regularScheduleId = :schedule')->setParameter('schedule', $scheduleId, 'ulid')
            ->orderBy('item.weekday', 'ASC')->addOrderBy('item.activeFrom', 'DESC');
        if (!$includeInactive) {
            $query->andWhere('item.inactiveFrom IS NULL');
        }

        return $query->getQuery()->getResult();
    }

    public function findDay(Ulid $scheduleId, Ulid $dayId): ?RegularScheduleDay
    {
        return $this->query(RegularScheduleDay::class)
            ->andWhere('item.regularScheduleId = :schedule')->setParameter('schedule', $scheduleId, 'ulid')
            ->andWhere('item.id = :id')->setParameter('id', $dayId, 'ulid')
            ->getQuery()->getOneOrNullResult();
    }

    public function openIssues(?Ulid $scheduleId = null): array
    {
        $query = $this->query(ScheduleGenerationIssue::class)
            ->andWhere("item.status = 'OPEN'")->orderBy('item.occurrenceDate', 'ASC');
        if (null !== $scheduleId) {
            $query->andWhere('item.regularScheduleId = :schedule')->setParameter('schedule', $scheduleId, 'ulid');
        }

        return $query->getQuery()->getResult();
    }

    public function findIssue(Ulid $issueId): ?ScheduleGenerationIssue
    {
        return $this->query(ScheduleGenerationIssue::class)
            ->andWhere('item.id = :id')->setParameter('id', $issueId, 'ulid')
            ->getQuery()->getOneOrNullResult();
    }

    public function findIssueForOccurrence(Ulid $scheduleId, \DateTimeImmutable $date): ?ScheduleGenerationIssue
    {
        return $this->query(ScheduleGenerationIssue::class)
            ->andWhere('item.regularScheduleId = :schedule')->setParameter('schedule', $scheduleId, 'ulid')
            ->andWhere('item.occurrenceDate = :date')->setParameter('date', $date, 'date_immutable')
            ->getQuery()->getOneOrNullResult();
    }

    public function save(RegularSchedule|RegularScheduleDay|ScheduleGenerationIssue ...$entities): void
    {
        foreach ($entities as $entity) {
            if (!OrganizationIsolation::belongsTo($this->organizationContext->currentId(), $entity)) {
                throw new \LogicException('Cannot write outside the current organization.');
            }
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    public function transactional(callable $operation): mixed
    {
        return $this->entityManager->wrapInTransaction($operation);
    }

    private function query(string $class): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('item')->from($class, 'item')
            ->andWhere('IDENTITY(item.organization) = :organization')
            ->setParameter('organization', $this->organizationContext->currentId(), 'ulid');
    }
}
