<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Infrastructure\Persistence;

use App\Module\Organization\Application\OrganizationContext;
use App\Module\Scheduling\Application\AppointmentStore;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\AppointmentEvent;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineAppointmentStore implements AppointmentStore
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrganizationContext $organizationContext,
    ) {
    }

    public function findRegularOccurrence(Ulid $scheduleId, \DateTimeImmutable $date): ?Appointment
    {
        return $this->entityManager->createQueryBuilder()->select('appointment')->from(Appointment::class, 'appointment')
            ->andWhere('IDENTITY(appointment.organization) = :organization')->setParameter('organization', $this->organizationContext->currentId(), 'ulid')
            ->andWhere('appointment.regularScheduleId = :schedule')->setParameter('schedule', $scheduleId, 'ulid')
            ->andWhere('appointment.occurrenceDate = :date')->setParameter('date', $date, 'date_immutable')
            ->andWhere('appointment.planningStatus = :status')->setParameter('status', 'PLANNED')
            ->getQuery()->getOneOrNullResult();
    }

    public function futureRegular(Ulid $scheduleId, \DateTimeImmutable $from, ?Ulid $dayId = null): array
    {
        $query = $this->entityManager->createQueryBuilder()->select('appointment')->from(Appointment::class, 'appointment')
            ->andWhere('IDENTITY(appointment.organization) = :organization')->setParameter('organization', $this->organizationContext->currentId(), 'ulid')
            ->andWhere('appointment.regularScheduleId = :schedule')->setParameter('schedule', $scheduleId, 'ulid')
            ->andWhere('appointment.startsAt >= :from')->setParameter('from', $from, 'datetimetz_immutable')
            ->andWhere('appointment.planningStatus = :status')->setParameter('status', 'PLANNED')
            ->orderBy('appointment.startsAt', 'ASC');
        if (null !== $dayId) {
            $query->andWhere('appointment.regularScheduleDayId = :day')->setParameter('day', $dayId, 'ulid');
        }

        return $query->getQuery()->getResult();
    }

    public function save(Appointment|AppointmentEvent $entity): void
    {
        if (!OrganizationIsolation::belongsTo($this->organizationContext->currentId(), $entity)) {
            throw new \LogicException('Cannot write outside the current organization.');
        }

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function transactional(callable $operation): mixed
    {
        return $this->entityManager->getConnection()->transactional(\Closure::fromCallable($operation));
    }
}
