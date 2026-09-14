<?php

declare(strict_types=1);

namespace App\Module\Waiting\Infrastructure\Persistence;

use App\Module\Organization\Application\OrganizationContext;
use App\Module\Waiting\Application\PermanentPlaceOfferStore;
use App\Module\Waiting\Domain\Model\PermanentPlaceOffer;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrinePermanentPlaceOfferStore implements PermanentPlaceOfferStore
{
    public function __construct(private EntityManagerInterface $entityManager, private OrganizationContext $context)
    {
    }

    public function find(Ulid $id): ?PermanentPlaceOffer
    {
        return $this->one($this->query()->andWhere('offer.id = :id')->setParameter('id', $id, 'ulid')->getQuery()->getOneOrNullResult());
    }

    public function lock(Ulid $id): ?PermanentPlaceOffer
    {
        return $this->one($this->query()->andWhere('offer.id = :id')->setParameter('id', $id, 'ulid')->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult());
    }

    public function activeForPlace(Ulid $placeId): ?PermanentPlaceOffer
    {
        return $this->one($this->query()->andWhere('offer.permanentPlaceId = :place')->setParameter('place', $placeId, 'ulid')->andWhere('offer.status = :status')->setParameter('status', 'ACTIVE')->getQuery()->getOneOrNullResult());
    }

    public function activeForPlaces(array $placeIds): array
    {
        if ([] === $placeIds) {
            return [];
        }
        $offers = $this->query()->andWhere('offer.permanentPlaceId IN (:places)')->setParameter('places', array_map(static fn (Ulid $id): string => $id->toRfc4122(), $placeIds))
            ->andWhere('offer.status = :status')->setParameter('status', 'ACTIVE')->getQuery()->getResult();
        $result = [];
        foreach ($offers as $offer) {
            if ($offer instanceof PermanentPlaceOffer) {
                $result[$offer->permanentPlaceId()->toRfc4122()] = $offer;
            }
        }

        return $result;
    }

    public function active(): array
    {
        return $this->query()->andWhere('offer.status = :status')->setParameter('status', 'ACTIVE')->orderBy('offer.createdAt', 'ASC')->getQuery()->getResult();
    }

    public function save(PermanentPlaceOffer $offer): void
    {
        if (!OrganizationIsolation::belongsTo($this->context->currentId(), $offer)) {
            throw new \LogicException('Cannot write permanent-place offer outside current organization.');
        }
        $this->entityManager->persist($offer);
        $this->entityManager->flush();
    }

    public function transactional(callable $operation): mixed
    {
        return $this->entityManager->getConnection()->transactional(\Closure::fromCallable($operation));
    }

    private function query(): \Doctrine\ORM\QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()->select('offer')->from(PermanentPlaceOffer::class, 'offer')
            ->andWhere('IDENTITY(offer.organization) = :organization')->setParameter('organization', $this->context->currentId(), 'ulid');
    }

    private function one(mixed $value): ?PermanentPlaceOffer
    {
        return $value instanceof PermanentPlaceOffer ? $value : null;
    }
}
