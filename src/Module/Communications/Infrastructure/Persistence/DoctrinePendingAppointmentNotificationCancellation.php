<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Persistence;

use App\Module\Communications\Application\PendingAppointmentNotificationCancellation;
use App\Module\Organization\Application\OrganizationContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrinePendingAppointmentNotificationCancellation implements PendingAppointmentNotificationCancellation
{
    public function __construct(private EntityManagerInterface $entityManager, private OrganizationContext $context)
    {
    }

    public function cancel(Ulid $appointmentId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE notification_intents
                SET status = 'CANCELLED'
                WHERE organization_id = :organization_id
                  AND status = 'PENDING'
                  AND type IN ('APPOINTMENT_CONFIRMATION_REQUEST', 'APPOINTMENT_REMINDER')
                  AND payload ->> 'appointmentId' = :appointment_id
                SQL,
            [
                'organization_id' => $this->context->currentId()->toRfc4122(),
                'appointment_id' => $appointmentId->toRfc4122(),
            ],
        );
    }
}
