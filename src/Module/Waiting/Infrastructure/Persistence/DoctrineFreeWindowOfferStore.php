<?php

declare(strict_types=1);

namespace App\Module\Waiting\Infrastructure\Persistence;

use App\Module\Organization\Application\OrganizationContext;
use App\Module\Waiting\Application\FreeWindowOfferStore;
use App\Module\Waiting\Domain\Model\FreeWindowOffer;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineFreeWindowOfferStore implements FreeWindowOfferStore
{
    public function __construct(private EntityManagerInterface $entityManager, private OrganizationContext $context)
    {
    }

    public function find(Ulid $id): ?FreeWindowOffer
    {
        $offer = $this->query()->andWhere('offer.id = :id')->setParameter('id', $id, 'ulid')->getQuery()->getOneOrNullResult();

        return $offer instanceof FreeWindowOffer ? $offer : null;
    }

    public function lock(Ulid $id): ?FreeWindowOffer
    {
        $offer = $this->query()->andWhere('offer.id = :id')->setParameter('id', $id, 'ulid')->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();

        return $offer instanceof FreeWindowOffer ? $offer : null;
    }

    public function activeForWindow(Ulid $windowId): ?FreeWindowOffer
    {
        $offer = $this->query()
            ->andWhere('offer.freeWindowId = :window')->setParameter('window', $windowId, 'ulid')
            ->andWhere('offer.status = :status')->setParameter('status', 'ACTIVE')
            ->getQuery()->getOneOrNullResult();

        return $offer instanceof FreeWindowOffer ? $offer : null;
    }

    public function activeForWindows(array $windowIds): array
    {
        if ([] === $windowIds) {
            return [];
        }
        $offers = $this->query()
            ->andWhere('offer.freeWindowId IN (:windows)')->setParameter('windows', array_map(static fn (Ulid $id): string => $id->toRfc4122(), $windowIds))
            ->andWhere('offer.status = :status')->setParameter('status', 'ACTIVE')
            ->getQuery()->getResult();
        $result = [];
        foreach ($offers as $offer) {
            if ($offer instanceof FreeWindowOffer) {
                $result[$offer->freeWindowId()->toRfc4122()] = $offer;
            }
        }

        return $result;
    }

    public function save(FreeWindowOffer $offer): void
    {
        if (!OrganizationIsolation::belongsTo($this->context->currentId(), $offer)) {
            throw new \LogicException('Cannot write free-window offer outside current organization.');
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
        return $this->entityManager->createQueryBuilder()->select('offer')->from(FreeWindowOffer::class, 'offer')
            ->andWhere('IDENTITY(offer.organization) = :organization')->setParameter('organization', $this->context->currentId(), 'ulid');
    }
}
