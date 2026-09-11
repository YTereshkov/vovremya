<?php

declare(strict_types=1);

namespace App\Module\Catalog\Infrastructure\Persistence;

use App\Module\Catalog\Application\ServiceStore;
use App\Module\Catalog\Application\ServiceNotificationTemplateResolver;
use App\Module\Catalog\Domain\Model\Service;
use App\Module\Organization\Application\OrganizationContext;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineServiceStore implements ServiceStore, ServiceNotificationTemplateResolver
{
    public function __construct(private EntityManagerInterface $entityManager, private OrganizationContext $organizationContext)
    {
    }

    public function active(): array
    {
        return $this->query()->orderBy('service.name', 'ASC')->addOrderBy('service.id', 'ASC')->getQuery()->getResult();
    }

    public function findActive(Ulid $id): ?Service
    {
        $result = $this->query()
            ->andWhere('service.id = :id')
            ->setParameter('id', $id, 'ulid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Service ? $result : null;
    }

    public function find(Ulid $id): ?Service
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('service')->from(Service::class, 'service')
            ->andWhere('IDENTITY(service.organization) = :organization')->setParameter('organization', $this->organizationContext->currentId(), 'ulid')
            ->andWhere('service.id = :id')->setParameter('id', $id, 'ulid')
            ->getQuery()->getOneOrNullResult();

        return $result instanceof Service ? $result : null;
    }

    public function save(Service $service): void
    {
        if (!OrganizationIsolation::belongsTo($this->organizationContext->currentId(), $service)) {
            throw new \LogicException('Cannot write outside the current organization.');
        }

        $this->entityManager->persist($service);
        $this->entityManager->flush();
    }

    public function confirmationTemplate(Ulid $serviceId): ?string
    {
        $value = $this->entityManager->createQueryBuilder()
            ->select('service.confirmationTemplate')
            ->from(Service::class, 'service')
            ->andWhere('IDENTITY(service.organization) = :organization')
            ->andWhere('service.id = :id')
            ->setParameter('organization', $this->organizationContext->currentId(), 'ulid')
            ->setParameter('id', $serviceId, 'ulid')
            ->getQuery()->getOneOrNullResult();

        return is_array($value) && is_string($value['confirmationTemplate'] ?? null)
            ? $value['confirmationTemplate']
            : null;
    }

    private function query(): \Doctrine\ORM\QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('service')
            ->from(Service::class, 'service')
            ->andWhere('IDENTITY(service.organization) = :organization')
            ->andWhere('service.deletedAt IS NULL')
            ->setParameter('organization', $this->organizationContext->currentId(), 'ulid');
    }
}
