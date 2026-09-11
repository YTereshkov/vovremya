<?php

declare(strict_types=1);

namespace App\Module\Waiting\Infrastructure\Persistence;

use App\Module\Organization\Application\OrganizationContext;
use App\Module\Waiting\Application\WaitingListStore;
use App\Module\Waiting\Domain\Model\WaitingListAvailability;
use App\Module\Waiting\Domain\Model\WaitingListEntry;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineWaitingListStore implements WaitingListStore
{
    public function __construct(private EntityManagerInterface $entityManager, private OrganizationContext $context)
    {
    }

    public function find(Ulid $id): ?WaitingListEntry
    {
        $entry = $this->entryQuery()->andWhere('entry.id = :id')->setParameter('id', $id, 'ulid')->getQuery()->getOneOrNullResult();

        return $entry instanceof WaitingListEntry ? $entry : null;
    }

    public function findActiveForClient(Ulid $clientId): ?WaitingListEntry
    {
        $entry = $this->entryQuery()
            ->andWhere('entry.clientId = :client')->setParameter('client', $clientId, 'ulid')
            ->andWhere('entry.active = TRUE')
            ->getQuery()->getOneOrNullResult();

        return $entry instanceof WaitingListEntry ? $entry : null;
    }

    public function availability(Ulid $entryId): array
    {
        return $this->entityManager->createQueryBuilder()->select('availability')->from(WaitingListAvailability::class, 'availability')
            ->andWhere('IDENTITY(availability.organization) = :organization')->setParameter('organization', $this->context->currentId(), 'ulid')
            ->andWhere('availability.waitingListEntryId = :entry')->setParameter('entry', $entryId, 'ulid')
            ->orderBy('availability.weekday', 'ASC')->getQuery()->getResult();
    }

    public function matchingOneOff(Ulid $serviceId, Ulid $specialistId, \DateTimeImmutable $date, int $weekday, string $startTime, string $endTime): array
    {
        return $this->entryQuery()
            ->distinct()
            ->innerJoin(WaitingListAvailability::class, 'availability', 'WITH', 'availability.waitingListEntryId = entry.id AND IDENTITY(availability.organization) = IDENTITY(entry.organization)')
            ->andWhere('entry.active = TRUE')
            ->andWhere('entry.readyForOneOff = TRUE')
            ->andWhere('entry.serviceId = :service')->setParameter('service', $serviceId, 'ulid')
            ->andWhere('(entry.specialistId IS NULL OR entry.specialistId = :specialist)')->setParameter('specialist', $specialistId, 'ulid')
            ->andWhere('entry.effectiveFrom <= :date')->setParameter('date', $date, 'date_immutable')
            ->andWhere('availability.weekday = :weekday')->setParameter('weekday', $weekday)
            ->andWhere('availability.startTime <= :start')->setParameter('start', $startTime)
            ->andWhere('(availability.endTime IS NULL OR availability.endTime >= :end)')->setParameter('end', $endTime)
            ->orderBy('entry.createdAt', 'ASC')->addOrderBy('entry.id', 'ASC')
            ->getQuery()->getResult();
    }

    public function save(WaitingListEntry|WaitingListAvailability ...$entities): void
    {
        foreach ($entities as $entity) {
            if (!OrganizationIsolation::belongsTo($this->context->currentId(), $entity)) {
                throw new \LogicException('Cannot write waiting list outside current organization.');
            }
            $this->entityManager->persist($entity);
        }
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            throw new \DomainException('Для клиента уже настроено активное ожидание.', 0, $exception);
        }
    }

    public function replaceAvailability(WaitingListEntry $entry, WaitingListAvailability ...$availability): void
    {
        if (!OrganizationIsolation::belongsTo($this->context->currentId(), $entry)) {
            throw new \LogicException('Cannot write waiting list outside current organization.');
        }
        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM waiting_list_availability WHERE organization_id = :organization AND waiting_list_entry_id = :entry',
            ['organization' => $this->context->currentId()->toRfc4122(), 'entry' => $entry->id()->toRfc4122()],
        );
        $this->save($entry, ...$availability);
    }

    public function transactional(callable $operation): mixed
    {
        return $this->entityManager->getConnection()->transactional(\Closure::fromCallable($operation));
    }

    private function entryQuery(): \Doctrine\ORM\QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()->select('entry')->from(WaitingListEntry::class, 'entry')
            ->andWhere('IDENTITY(entry.organization) = :organization')->setParameter('organization', $this->context->currentId(), 'ulid');
    }
}
