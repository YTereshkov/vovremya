<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Infrastructure\Persistence;

use App\Module\Organization\Application\OrganizationContext;
use App\Module\Scheduling\Application\AppointmentStore;
use App\Module\Scheduling\Application\EarlierAppointmentCandidate;
use App\Module\Scheduling\Application\EarlierAppointmentCandidateSource;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\AppointmentEvent;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineAppointmentStore implements AppointmentStore, EarlierAppointmentCandidateSource
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrganizationContext $organizationContext,
    ) {
    }

    public function find(Ulid $id): ?Appointment
    {
        $appointment = $this->entityManager->createQueryBuilder()->select('appointment')->from(Appointment::class, 'appointment')
            ->andWhere('IDENTITY(appointment.organization) = :organization')->setParameter('organization', $this->organizationContext->currentId(), 'ulid')
            ->andWhere('appointment.id = :id')->setParameter('id', $id, 'ulid')
            ->getQuery()->getOneOrNullResult();

        return $appointment instanceof Appointment ? $appointment : null;
    }

    public function lock(Ulid $id): ?Appointment
    {
        $appointment = $this->appointmentQuery()
            ->andWhere('appointment.id = :id')->setParameter('id', $id, 'ulid')
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();

        return $appointment instanceof Appointment ? $appointment : null;
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

    public function plannedForSpecialistBetween(Ulid $specialistId, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): array
    {
        return $this->plannedBetween($startsAt, $endsAt)
            ->andWhere('appointment.specialistId = :owner')->setParameter('owner', $specialistId, 'ulid')
            ->getQuery()->getResult();
    }

    public function lockPlannedForSpecialistBetween(Ulid $specialistId, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): array
    {
        return $this->plannedBetween($startsAt, $endsAt)
            ->andWhere('appointment.specialistId = :owner')->setParameter('owner', $specialistId, 'ulid')
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getResult();
    }

    public function plannedForClientBetween(Ulid $clientId, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, bool $oneOffOnly = false): array
    {
        $query = $this->plannedBetween($startsAt, $endsAt)
            ->andWhere('appointment.clientId = :owner')->setParameter('owner', $clientId, 'ulid');
        if ($oneOffOnly) {
            $query->andWhere('appointment.regularScheduleId IS NULL');
        }

        return $query->getQuery()->getResult();
    }

    public function lockPlannedForClientBetween(Ulid $clientId, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, bool $oneOffOnly = false): array
    {
        $query = $this->plannedBetween($startsAt, $endsAt)
            ->andWhere('appointment.clientId = :owner')->setParameter('owner', $clientId, 'ulid');
        if ($oneOffOnly) {
            $query->andWhere('appointment.regularScheduleId IS NULL');
        }

        return $query->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getResult();
    }

    public function save(Appointment|AppointmentEvent $entity): void
    {
        if (!OrganizationIsolation::belongsTo($this->organizationContext->currentId(), $entity)) {
            throw new \LogicException('Cannot write outside the current organization.');
        }

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function laterAppointments(Ulid $specialistId, Ulid $serviceId, \DateTimeImmutable $after, \DateTimeImmutable $before): array
    {
        $appointments = $this->appointmentQuery()
            ->andWhere('appointment.specialistId = :specialist')->setParameter('specialist', $specialistId, 'ulid')
            ->andWhere('appointment.serviceId = :service')->setParameter('service', $serviceId, 'ulid')
            ->andWhere('appointment.startsAt > :after')->setParameter('after', $after, 'datetimetz_immutable')
            ->andWhere('appointment.startsAt < :before')->setParameter('before', $before, 'datetimetz_immutable')
            ->andWhere('appointment.planningStatus = :status')->setParameter('status', 'PLANNED')
            ->andWhere('appointment.resultStatus IS NULL')
            ->orderBy('appointment.startsAt', 'ASC')->addOrderBy('appointment.id', 'ASC')
            ->getQuery()->getResult();

        return array_map(static fn (Appointment $appointment): EarlierAppointmentCandidate => new EarlierAppointmentCandidate(
            $appointment->id(),
            $appointment->clientId(),
            $appointment->startsAt(),
            $appointment->durationMinutes(),
        ), $appointments);
    }

    public function history(Ulid $appointmentId): array
    {
        return $this->entityManager->createQueryBuilder()->select('event')->from(AppointmentEvent::class, 'event')
            ->andWhere('IDENTITY(event.organization) = :organization')->setParameter('organization', $this->organizationContext->currentId(), 'ulid')
            ->andWhere('event.appointmentId = :appointment')->setParameter('appointment', $appointmentId, 'ulid')
            ->orderBy('event.occurredAt', 'ASC')->addOrderBy('event.id', 'ASC')
            ->getQuery()->getResult();
    }

    public function transactional(callable $operation): mixed
    {
        return $this->entityManager->getConnection()->transactional(\Closure::fromCallable($operation));
    }

    private function plannedBetween(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): \Doctrine\ORM\QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()->select('appointment')->from(Appointment::class, 'appointment')
            ->andWhere('IDENTITY(appointment.organization) = :organization')->setParameter('organization', $this->organizationContext->currentId(), 'ulid')
            ->andWhere('appointment.startsAt >= :startsAt')->setParameter('startsAt', $startsAt, 'datetimetz_immutable')
            ->andWhere('appointment.startsAt < :endsAt')->setParameter('endsAt', $endsAt, 'datetimetz_immutable')
            ->andWhere('appointment.planningStatus = :status')->setParameter('status', 'PLANNED')
            ->andWhere('appointment.resultStatus IS NULL')
            ->orderBy('appointment.startsAt', 'ASC')->addOrderBy('appointment.id', 'ASC');
    }

    private function appointmentQuery(): \Doctrine\ORM\QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()->select('appointment')->from(Appointment::class, 'appointment')
            ->andWhere('IDENTITY(appointment.organization) = :organization')->setParameter('organization', $this->organizationContext->currentId(), 'ulid');
    }
}
