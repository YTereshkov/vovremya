<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Infrastructure\Persistence;

use App\Module\Organization\Application\OrganizationContext;
use App\Module\Scheduling\Application\AppointmentConfirmationStore;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\AppointmentConfirmationAction;
use App\Module\Scheduling\Domain\Model\AppointmentConfirmationRequest;
use App\Module\Scheduling\Domain\Model\ConfirmationActionType;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineAppointmentConfirmationStore implements AppointmentConfirmationStore
{
    public function __construct(private EntityManagerInterface $entityManager, private OrganizationContext $context)
    {
    }

    public function findAppointment(Ulid $id): ?Appointment
    {
        $result = $this->entityManager->createQueryBuilder()->select('item')->from(Appointment::class, 'item')
            ->andWhere('IDENTITY(item.organization) = :organization')->setParameter('organization', $this->context->currentId(), 'ulid')
            ->andWhere('item.id = :id')->setParameter('id', $id, 'ulid')
            ->andWhere('item.planningStatus = :status')->setParameter('status', 'PLANNED')
            ->getQuery()->getOneOrNullResult();

        return $result instanceof Appointment ? $result : null;
    }

    public function findByAppointment(Ulid $appointmentId): ?AppointmentConfirmationRequest
    {
        $result = $this->entityManager->createQueryBuilder()->select('item')->from(AppointmentConfirmationRequest::class, 'item')
            ->andWhere('IDENTITY(item.organization) = :organization')->setParameter('organization', $this->context->currentId(), 'ulid')
            ->andWhere('item.appointmentId = :appointment')->setParameter('appointment', $appointmentId, 'ulid')
            ->getQuery()->getOneOrNullResult();

        return $result instanceof AppointmentConfirmationRequest ? $result : null;
    }

    public function futureAppointments(\DateTimeImmutable $now, \DateTimeImmutable $until): array
    {
        return $this->entityManager->createQueryBuilder()->select('item')->from(Appointment::class, 'item')
            ->andWhere('IDENTITY(item.organization) = :organization')->setParameter('organization', $this->context->currentId(), 'ulid')
            ->andWhere('item.planningStatus = :status')->setParameter('status', 'PLANNED')
            ->andWhere('item.startsAt > :now')->setParameter('now', $now, 'datetimetz_immutable')
            ->andWhere('item.startsAt <= :until')->setParameter('until', $until, 'datetimetz_immutable')
            ->orderBy('item.startsAt', 'ASC')->getQuery()->getResult();
    }

    public function save(AppointmentConfirmationRequest|AppointmentConfirmationAction $entity): void
    {
        if (!OrganizationIsolation::belongsTo($this->context->currentId(), $entity)) {
            throw new \LogicException('Cannot write confirmation outside current organization.');
        }
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function consumeAction(Ulid $actionId, string $token, Ulid $channelConnectionId, \DateTimeImmutable $now): ?ConfirmationActionType
    {
        $action = $this->entityManager->getConnection()->fetchOne(
            <<<'SQL'
                WITH candidate AS (
                    SELECT action.confirmation_request_id, action.action_type
                    FROM appointment_confirmation_actions action
                    INNER JOIN appointment_confirmation_requests request
                        ON request.organization_id = action.organization_id
                       AND request.id = action.confirmation_request_id
                    INNER JOIN appointments appointment
                        ON appointment.organization_id = request.organization_id
                       AND appointment.id = request.appointment_id
                    WHERE action.organization_id = :organization
                      AND action.id = :action_id
                      AND action.token_hash = :token_hash
                      AND action.consumed_at IS NULL
                      AND request.channel_connection_id = :channel_connection_id
                      AND request.status IN ('PENDING', 'NO_RESPONSE')
                      AND appointment.planning_status = 'PLANNED'
                      AND appointment.starts_at > :now
                    FOR UPDATE OF action, request
                ), updated_request AS (
                    UPDATE appointment_confirmation_requests request
                    SET status = CASE candidate.action_type
                            WHEN 'CONFIRM' THEN 'CONFIRMED'
                            ELSE 'CANNOT_ATTEND'
                        END,
                        responded_at = :now
                    FROM candidate
                    WHERE request.organization_id = :organization
                      AND request.id = candidate.confirmation_request_id
                    RETURNING request.id, candidate.action_type
                ), consumed_actions AS (
                    UPDATE appointment_confirmation_actions action
                    SET consumed_at = :now
                    FROM updated_request
                    WHERE action.organization_id = :organization
                      AND action.confirmation_request_id = updated_request.id
                    RETURNING updated_request.action_type
                )
                SELECT action_type FROM consumed_actions LIMIT 1
                SQL,
            [
                'organization' => $this->context->currentId()->toRfc4122(),
                'action_id' => $actionId->toRfc4122(),
                'token_hash' => hash('sha256', $token),
                'channel_connection_id' => $channelConnectionId->toRfc4122(),
                'now' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'),
            ],
        );

        return false === $action ? null : ConfirmationActionType::from((string) $action);
    }

    public function noResponseAttention(\DateTimeImmutable $now): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT appointment.id AS "appointmentId", client.name AS "clientName", appointment.starts_at AS "startsAt"
                FROM appointment_confirmation_requests request
                INNER JOIN appointments appointment
                    ON appointment.organization_id = request.organization_id AND appointment.id = request.appointment_id
                INNER JOIN clients client
                    ON client.organization_id = appointment.organization_id AND client.id = appointment.client_id
                WHERE request.organization_id = :organization
                  AND request.status = 'NO_RESPONSE'
                  AND appointment.planning_status = 'PLANNED'
                  AND appointment.starts_at > :now
                ORDER BY appointment.starts_at, appointment.id
                SQL,
            [
                'organization' => $this->context->currentId()->toRfc4122(),
                'now' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'),
            ],
        );
    }

    public function transactional(callable $operation): mixed
    {
        return $this->entityManager->wrapInTransaction($operation);
    }
}
