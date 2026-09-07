<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'appointments')]
#[ORM\UniqueConstraint(name: 'uniq_appointments_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\Index(name: 'idx_appointments_tenant_start', columns: ['organization_id', 'starts_at', 'id'])]
#[ORM\Index(name: 'idx_appointments_tenant_specialist', columns: ['organization_id', 'specialist_id', 'starts_at'])]
#[ORM\Index(name: 'idx_appointments_tenant_client', columns: ['organization_id', 'client_id'])]
#[ORM\Index(name: 'idx_appointments_tenant_service', columns: ['organization_id', 'service_id'])]
final class Appointment implements OrganizationOwned
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(name: 'specialist_id', type: 'ulid')]
        private Ulid $specialistId,
        #[ORM\Column(name: 'client_id', type: 'ulid')]
        private Ulid $clientId,
        #[ORM\Column(name: 'service_id', type: 'ulid')]
        private Ulid $serviceId,
        #[ORM\Column(name: 'service_name_snapshot', length: 160)]
        private string $serviceNameSnapshot,
        #[ORM\Column(name: 'service_default_duration_snapshot', type: Types::SMALLINT)]
        private int $serviceDefaultDurationSnapshot,
        #[ORM\Column(name: 'service_minimum_duration_snapshot', type: Types::SMALLINT, nullable: true)]
        private ?int $serviceMinimumDurationSnapshot,
        #[ORM\Column(name: 'service_maximum_duration_snapshot', type: Types::SMALLINT, nullable: true)]
        private ?int $serviceMaximumDurationSnapshot,
        #[ORM\Column(name: 'duration_minutes', type: Types::SMALLINT)]
        private int $durationMinutes,
        #[ORM\Column(name: 'starts_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $startsAt,
        #[ORM\Column(name: 'ends_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $endsAt,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(
        Organization $organization,
        Ulid $specialistId,
        Ulid $clientId,
        Ulid $serviceId,
        string $serviceName,
        int $serviceDefaultDuration,
        ?int $serviceMinimumDuration,
        ?int $serviceMaximumDuration,
        int $durationMinutes,
        \DateTimeImmutable $startsAt,
    ): self {
        if (1 > $durationMinutes || 1440 < $durationMinutes
            || (null !== $serviceMinimumDuration && $durationMinutes < $serviceMinimumDuration)
            || (null !== $serviceMaximumDuration && $durationMinutes > $serviceMaximumDuration)) {
            throw new \InvalidArgumentException('Длительность занятия не входит в допустимый диапазон услуги.');
        }

        $utc = new \DateTimeZone('UTC');
        $startUtc = $startsAt->setTimezone($utc);

        return new self(
            new Ulid(),
            $organization,
            $specialistId,
            $clientId,
            $serviceId,
            $serviceName,
            $serviceDefaultDuration,
            $serviceMinimumDuration,
            $serviceMaximumDuration,
            $durationMinutes,
            $startUtc,
            $startUtc->modify(sprintf('+%d minutes', $durationMinutes)),
            new \DateTimeImmutable('now', $utc),
        );
    }

    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function specialistId(): Ulid { return $this->specialistId; }
    public function clientId(): Ulid { return $this->clientId; }
    public function serviceId(): Ulid { return $this->serviceId; }
    public function serviceName(): string { return $this->serviceNameSnapshot; }
    public function serviceDefaultDuration(): int { return $this->serviceDefaultDurationSnapshot; }
    public function serviceMinimumDuration(): ?int { return $this->serviceMinimumDurationSnapshot; }
    public function serviceMaximumDuration(): ?int { return $this->serviceMaximumDurationSnapshot; }
    public function durationMinutes(): int { return $this->durationMinutes; }
    public function startsAt(): \DateTimeImmutable { return $this->startsAt; }
    public function endsAt(): \DateTimeImmutable { return $this->endsAt; }
}
