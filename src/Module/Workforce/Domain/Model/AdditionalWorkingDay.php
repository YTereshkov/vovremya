<?php

declare(strict_types=1);

namespace App\Module\Workforce\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'additional_working_days')]
#[ORM\UniqueConstraint(name: 'uniq_additional_days_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_additional_days_date', columns: ['organization_id', 'specialist_id', 'date'])]
final class AdditionalWorkingDay implements OrganizationOwned
{
    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Organization $organization;

    #[ORM\Column(type: 'ulid')]
    private Ulid $specialistId;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'jsonb')]
    private array $work;

    public function __construct(
        #[ORM\Id, ORM\Column(type: 'ulid')]
        private Ulid $id,
        Specialist $specialist,
        string $date,
        array $work,
    ) {
        $this->organization = $specialist->organization();
        $this->specialistId = $specialist->id();
        $this->change($date, $work);
    }

    public function change(string $date, array $work): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        if (false === $parsed || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Укажите существующую дату в формате ГГГГ-ММ-ДД.');
        }
        $validated = WorkInterval::validate($work);
        $this->date = $parsed;
        $this->work = $validated;
    }

    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function specialistId(): Ulid { return $this->specialistId; }
    public function date(): string { return $this->date->format('Y-m-d'); }
    public function work(): array { return $this->work; }
}
