<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Infrastructure\Persistence;

use App\Module\Organization\Application\OrganizationContext;
use App\Module\Scheduling\Application\TransferStore;
use App\Module\Scheduling\Domain\Model\TransferOption;
use App\Module\Scheduling\Domain\Model\TransferRequest;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineTransferStore implements TransferStore
{
    public function __construct(private EntityManagerInterface $entityManager, private OrganizationContext $context)
    {
    }

    public function find(Ulid $id): ?TransferRequest
    {
        $result = $this->requestQuery()->andWhere('item.id = :id')->setParameter('id', $id, 'ulid')->getQuery()->getOneOrNullResult();

        return $result instanceof TransferRequest ? $result : null;
    }

    public function findActiveForAppointment(Ulid $appointmentId): ?TransferRequest
    {
        $result = $this->requestQuery()
            ->andWhere('item.appointmentId = :appointment')->setParameter('appointment', $appointmentId, 'ulid')
            ->andWhere('item.status IN (:statuses)')->setParameter('statuses', ['AWAITING_OPTIONS', 'OPTIONS_SENT'])
            ->orderBy('item.createdAt', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        return $result instanceof TransferRequest ? $result : null;
    }

    public function lock(Ulid $id): ?TransferRequest
    {
        $query = $this->requestQuery()->andWhere('item.id = :id')->setParameter('id', $id, 'ulid')->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $result = $query->getOneOrNullResult();

        return $result instanceof TransferRequest ? $result : null;
    }

    public function findOption(Ulid $id): ?TransferOption
    {
        $result = $this->entityManager->createQueryBuilder()->select('item')->from(TransferOption::class, 'item')
            ->andWhere('IDENTITY(item.organization) = :organization')->setParameter('organization', $this->context->currentId(), 'ulid')
            ->andWhere('item.id = :id')->setParameter('id', $id, 'ulid')
            ->getQuery()->getOneOrNullResult();

        return $result instanceof TransferOption ? $result : null;
    }

    public function options(Ulid $requestId): array
    {
        return $this->entityManager->createQueryBuilder()->select('item')->from(TransferOption::class, 'item')
            ->andWhere('IDENTITY(item.organization) = :organization')->setParameter('organization', $this->context->currentId(), 'ulid')
            ->andWhere('item.transferRequestId = :request')->setParameter('request', $requestId, 'ulid')
            ->orderBy('item.startsAt', 'ASC')->addOrderBy('item.id', 'ASC')
            ->getQuery()->getResult();
    }

    public function save(TransferRequest|TransferOption ...$entities): void
    {
        foreach ($entities as $entity) {
            if (!OrganizationIsolation::belongsTo($this->context->currentId(), $entity)) {
                throw new \LogicException('Cannot write transfer outside current organization.');
            }
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    public function transactional(callable $operation): mixed
    {
        return $this->entityManager->getConnection()->transactional(\Closure::fromCallable($operation));
    }

    private function requestQuery(): \Doctrine\ORM\QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()->select('item')->from(TransferRequest::class, 'item')
            ->andWhere('IDENTITY(item.organization) = :organization')->setParameter('organization', $this->context->currentId(), 'ulid');
    }
}
