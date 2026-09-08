<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain\Model;

use App\Module\Catalog\Domain\Model\Service;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Organization\Domain\Model\Organization;
use App\Module\Workforce\Domain\Model\Specialist;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'regular_schedules')]
#[ORM\UniqueConstraint(name: 'uniq_regular_schedules_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\Index(name: 'idx_regular_schedules_active', columns: ['organization_id', 'inactive_from', 'starts_on'])]
#[ORM\Index(name: 'idx_regular_schedules_client', columns: ['organization_id', 'client_id'])]
final class RegularSchedule implements OrganizationOwned
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
        #[ORM\Column(name: 'starts_on', type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $startsOn,
        #[ORM\Column(name: 'inactive_from', type: Types::DATE_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $inactiveFrom,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(
        Organization $organization,
        Specialist $specialist,
        Client $client,
        Service $service,
        \DateTimeImmutable $startsOn,
        ?\DateTimeImmutable $endsOn,
    ): self {
        OrganizationIsolation::assertCanAssociate($specialist, $client, $service);
        if (!$organization->id()->equals($specialist->organizationId())) {
            throw new \LogicException('Cannot create a regular schedule across organizations.');
        }
        $start = self::date($startsOn);
        $inactiveFrom = null === $endsOn ? null : self::date($endsOn)->modify('+1 day');
        if (null !== $inactiveFrom && $inactiveFrom <= $start) {
            throw new \InvalidArgumentException('Дата окончания должна быть не раньше даты начала.');
        }

        return new self(
            new Ulid(),
            $organization,
            $specialist->id(),
            $client->id(),
            $service->id(),
            $service->name(),
            $service->defaultDurationMinutes(),
            $service->minimumDurationMinutes(),
            $service->maximumDurationMinutes(),
            $start,
            $inactiveFrom,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    public function endFrom(\DateTimeImmutable $date): void
    {
        $date = self::date($date);
        if ($date < $this->startsOn) {
            throw new \InvalidArgumentException('Регулярное расписание нельзя завершить раньше его начала.');
        }
        if (null !== $this->inactiveFrom && $date >= $this->inactiveFrom) {
            return;
        }
        $this->inactiveFrom = $date;
    }

    public function isActiveOn(\DateTimeImmutable $date): bool
    {
        $date = self::date($date);

        return $date >= $this->startsOn && (null === $this->inactiveFrom || $date < $this->inactiveFrom);
    }

    public function id(): Ulid { return $this->id; }
    public function organization(): Organization { return $this->organization; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function specialistId(): Ulid { return $this->specialistId; }
    public function clientId(): Ulid { return $this->clientId; }
    public function serviceId(): Ulid { return $this->serviceId; }
    public function serviceName(): string { return $this->serviceNameSnapshot; }
    public function serviceDefaultDuration(): int { return $this->serviceDefaultDurationSnapshot; }
    public function serviceMinimumDuration(): ?int { return $this->serviceMinimumDurationSnapshot; }
    public function serviceMaximumDuration(): ?int { return $this->serviceMaximumDurationSnapshot; }
    public function startsOn(): \DateTimeImmutable { return $this->startsOn; }
    public function inactiveFrom(): ?\DateTimeImmutable { return $this->inactiveFrom; }
    public function endsOn(): ?\DateTimeImmutable { return $this->inactiveFrom?->modify('-1 day'); }

    private static function date(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'));
    }
}
