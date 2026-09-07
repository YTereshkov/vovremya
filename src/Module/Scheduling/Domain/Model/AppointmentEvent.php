<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'appointment_events')]
#[ORM\UniqueConstraint(name: 'uniq_appointment_events_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\Index(name: 'idx_appointment_events_appointment', columns: ['organization_id', 'appointment_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_appointment_events_actor', columns: ['organization_id', 'actor_administrator_id'])]
final class AppointmentEvent implements OrganizationOwned
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(name: 'appointment_id', type: 'ulid')]
        private Ulid $appointmentId,
        #[ORM\Column(name: 'actor_administrator_id', type: 'ulid')]
        private Ulid $actorAdministratorId,
        #[ORM\Column(name: 'event_type', length: 48)]
        private string $type,
        #[ORM\Column(type: 'jsonb')]
        private array $payload,
        #[ORM\Column(name: 'occurred_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $occurredAt,
    ) {
    }

    /** @param list<array<string, mixed>> $warnings */
    public static function softWarningsAccepted(Appointment $appointment, Organization $organization, Ulid $actorId, array $warnings): self
    {
        if (!$appointment->organizationId()->equals($organization->id())) {
            throw new \LogicException('Cannot record an appointment event across organizations.');
        }

        return new self(
            new Ulid(),
            $organization,
            $appointment->id(),
            $actorId,
            'SOFT_WARNINGS_ACCEPTED',
            ['warnings' => $warnings],
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    public function organizationId(): Ulid { return $this->organization->id(); }
    public function appointmentId(): Ulid { return $this->appointmentId; }
    public function type(): string { return $this->type; }
    /** @return array<string, mixed> */
    public function payload(): array { return $this->payload; }
}
