<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'scheduling_settings')]
#[ORM\UniqueConstraint(name: 'uniq_scheduling_settings_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_scheduling_settings_organization', columns: ['organization_id'])]
final class SchedulingSettings implements OrganizationOwned
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\OneToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(name: 'late_cancellation_hours')]
        private int $lateCancellationHours,
    ) {
    }

    public static function defaults(Organization $organization): self
    {
        return new self(new Ulid(), $organization, 12);
    }

    public function changeLateCancellationHours(int $hours): void
    {
        if (1 > $hours || 168 < $hours) {
            throw new \InvalidArgumentException('Порог поздней отмены должен быть от 1 до 168 часов.');
        }
        $this->lateCancellationHours = $hours;
    }

    public function organizationId(): Ulid { return $this->organization->id(); }
    public function lateCancellationHours(): int { return $this->lateCancellationHours; }
}
