<?php

declare(strict_types=1);

namespace App\Module\Workforce\Infrastructure\Persistence;

use App\Module\Organization\Application\OrganizationContext;
use App\Module\Workforce\Application\WorkforceStore;
use App\Module\Workforce\Domain\Model\AdditionalWorkingDay;
use App\Module\Workforce\Domain\Model\Specialist;
use App\Module\Workforce\Domain\Model\SpecialistAbsence;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineWorkforceStore implements WorkforceStore
{
    public function __construct(private EntityManagerInterface $em, private OrganizationContext $context) {}

    public function all(): array { return $this->query(Specialist::class)->orderBy('item.name', 'ASC')->getQuery()->getResult(); }

    public function find(Ulid $id): ?Specialist
    {
        return $this->query(Specialist::class)->andWhere('item.id = :id')->setParameter('id', $id, 'ulid')->getQuery()->getOneOrNullResult();
    }

    public function additionalDays(?Ulid $specialistId = null): array
    {
        $query = $this->query(AdditionalWorkingDay::class)->orderBy('item.date', 'ASC');
        if (null !== $specialistId) {
            $query->andWhere('item.specialistId = :id')->setParameter('id', $specialistId, 'ulid');
        }

        return $query->getQuery()->getResult();
    }

    public function absences(?Ulid $specialistId = null): array
    {
        $query = $this->query(SpecialistAbsence::class)->orderBy('item.startsOn', 'DESC')->addOrderBy('item.id', 'ASC');
        if (null !== $specialistId) {
            $query->andWhere('item.specialistId = :id')->setParameter('id', $specialistId, 'ulid');
        }

        return $query->getQuery()->getResult();
    }

    public function save(Specialist|AdditionalWorkingDay|SpecialistAbsence $entity): void
    {
        $this->assertScope($entity);
        try {
            $this->em->persist($entity);
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            throw new \DomainException('Профиль администратора или рабочий день уже добавлен.', 0, $e);
        }
    }

    public function remove(Specialist|AdditionalWorkingDay $entity): void
    {
        $this->assertScope($entity);
        $this->em->remove($entity);
        $this->em->flush();
    }

    public function transactional(callable $operation): mixed
    {
        return $this->em->wrapInTransaction($operation);
    }

    private function query(string $class): QueryBuilder
    {
        return $this->em->createQueryBuilder()->select('item')->from($class, 'item')
            ->andWhere('IDENTITY(item.organization) = :organization')->setParameter('organization', $this->context->currentId(), 'ulid');
    }

    private function assertScope(Specialist|AdditionalWorkingDay|SpecialistAbsence $entity): void
    {
        if (!OrganizationIsolation::belongsTo($this->context->currentId(), $entity)) {
            throw new \LogicException('Cannot write outside the current organization.');
        }
    }
}
