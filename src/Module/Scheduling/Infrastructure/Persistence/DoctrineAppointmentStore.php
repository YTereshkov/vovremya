<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Infrastructure\Persistence;

use App\Module\Organization\Application\OrganizationContext;
use App\Module\Scheduling\Application\AppointmentStore;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\AppointmentEvent;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAppointmentStore implements AppointmentStore
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrganizationContext $organizationContext,
    ) {
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
        return $this->entityManager->wrapInTransaction($operation);
    }
}
