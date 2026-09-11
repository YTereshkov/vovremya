<?php

declare(strict_types=1);

namespace App\Module\Waiting\Infrastructure\Persistence;

use App\Module\Organization\Application\OrganizationContext;
use App\Module\Waiting\Application\FreeWindowStore;
use App\Module\Waiting\Domain\Model\FreeWindow;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineFreeWindowStore implements FreeWindowStore
{
    public function __construct(private EntityManagerInterface $entityManager, private OrganizationContext $context)
    {
    }

    public function find(Ulid $id): ?FreeWindow
    {
        $window = $this->query()->andWhere('window.id = :id')->setParameter('id', $id, 'ulid')->getQuery()->getOneOrNullResult();

        return $window instanceof FreeWindow ? $window : null;
    }

    public function findBySourceAppointment(Ulid $appointmentId): ?FreeWindow
    {
        $window = $this->query()
            ->andWhere('window.sourceAppointmentId = :appointment')->setParameter('appointment', $appointmentId, 'ulid')
            ->getQuery()->getOneOrNullResult();

        return $window instanceof FreeWindow ? $window : null;
    }

    public function openFuture(\DateTimeImmutable $now): array
    {
        return $this->query()
            ->andWhere('window.status = :status')->setParameter('status', 'OPEN')
            ->andWhere('window.startsAt > :now')->setParameter('now', $now, 'datetimetz_immutable')
            ->orderBy('window.startsAt', 'ASC')->addOrderBy('window.id', 'ASC')
            ->getQuery()->getResult();
    }

    public function save(FreeWindow $window): void
    {
        if (!OrganizationIsolation::belongsTo($this->context->currentId(), $window)) {
            throw new \LogicException('Cannot write free window outside current organization.');
        }
        $this->entityManager->persist($window);
        $this->entityManager->flush();
    }

    private function query(): \Doctrine\ORM\QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()->select('window')->from(FreeWindow::class, 'window')
            ->andWhere('IDENTITY(window.organization) = :organization')->setParameter('organization', $this->context->currentId(), 'ulid');
    }
}
