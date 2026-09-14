<?php

declare(strict_types=1);

namespace App\Module\Waiting\Infrastructure\Persistence;

use App\Module\Organization\Application\OrganizationContext;
use App\Module\Waiting\Application\PermanentPlaceStore;
use App\Module\Waiting\Domain\Model\PermanentPlace;
use App\Module\Waiting\Domain\Model\PermanentPlaceSlot;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrinePermanentPlaceStore implements PermanentPlaceStore
{
    public function __construct(private EntityManagerInterface $entityManager, private OrganizationContext $context)
    {
    }

    public function find(Ulid $id): ?PermanentPlace
    {
        return $this->one($this->query()->andWhere('place.id = :id')->setParameter('id', $id, 'ulid')->getQuery()->getOneOrNullResult());
    }

    public function lock(Ulid $id): ?PermanentPlace
    {
        return $this->one($this->query()->andWhere('place.id = :id')->setParameter('id', $id, 'ulid')->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult());
    }

    public function findBySourceSchedule(Ulid $scheduleId): ?PermanentPlace
    {
        return $this->one($this->query()->andWhere('place.sourceRegularScheduleId = :schedule')->setParameter('schedule', $scheduleId, 'ulid')->andWhere('place.type = :type')->setParameter('type', 'BUNDLE')->getQuery()->getOneOrNullResult());
    }

    public function findBySourceDay(Ulid $dayId): ?PermanentPlace
    {
        return $this->one($this->query()->andWhere('place.sourceRegularScheduleDayId = :day')->setParameter('day', $dayId, 'ulid')->getQuery()->getOneOrNullResult());
    }

    public function open(): array
    {
        return $this->query()->andWhere('place.status = :status')->setParameter('status', 'OPEN')->orderBy('place.availableFrom', 'ASC')->addOrderBy('place.createdAt', 'ASC')->getQuery()->getResult();
    }

    public function slots(Ulid $placeId): array
    {
        return $this->entityManager->createQueryBuilder()->select('slot')->from(PermanentPlaceSlot::class, 'slot')
            ->andWhere('IDENTITY(slot.organization) = :organization')->setParameter('organization', $this->context->currentId(), 'ulid')
            ->andWhere('slot.permanentPlaceId = :place')->setParameter('place', $placeId, 'ulid')
            ->orderBy('slot.weekday', 'ASC')->getQuery()->getResult();
    }

    public function slotsForPlaces(array $placeIds): array
    {
        if ([] === $placeIds) {
            return [];
        }
        $slots = $this->entityManager->createQueryBuilder()->select('slot')->from(PermanentPlaceSlot::class, 'slot')
            ->andWhere('IDENTITY(slot.organization) = :organization')->setParameter('organization', $this->context->currentId(), 'ulid')
            ->andWhere('slot.permanentPlaceId IN (:places)')->setParameter('places', array_map(static fn (Ulid $id): string => $id->toRfc4122(), $placeIds))
            ->orderBy('slot.weekday', 'ASC')->getQuery()->getResult();
        $result = [];
        foreach ($slots as $slot) {
            if ($slot instanceof PermanentPlaceSlot) {
                $result[$slot->permanentPlaceId()->toRfc4122()][] = $slot;
            }
        }

        return $result;
    }

    public function save(PermanentPlace|PermanentPlaceSlot ...$entities): void
    {
        foreach ($entities as $entity) {
            if (!OrganizationIsolation::belongsTo($this->context->currentId(), $entity)) {
                throw new \LogicException('Cannot write permanent place outside current organization.');
            }
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    public function transactional(callable $operation): mixed
    {
        return $this->entityManager->getConnection()->transactional(\Closure::fromCallable($operation));
    }

    private function query(): \Doctrine\ORM\QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()->select('place')->from(PermanentPlace::class, 'place')
            ->andWhere('IDENTITY(place.organization) = :organization')->setParameter('organization', $this->context->currentId(), 'ulid');
    }

    private function one(mixed $value): ?PermanentPlace
    {
        return $value instanceof PermanentPlace ? $value : null;
    }
}
