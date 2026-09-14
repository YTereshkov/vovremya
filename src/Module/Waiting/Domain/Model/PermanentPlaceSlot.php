<?php

declare(strict_types=1);

namespace App\Module\Waiting\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Module\Scheduling\Domain\Model\RegularScheduleDay;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'permanent_place_slots')]
#[ORM\UniqueConstraint(name: 'uniq_permanent_place_slots_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_permanent_place_slots_weekday', columns: ['organization_id', 'permanent_place_id', 'weekday'])]
final class PermanentPlaceSlot implements OrganizationOwned
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(name: 'permanent_place_id', type: 'ulid')]
        private Ulid $permanentPlaceId,
        #[ORM\Column(type: Types::SMALLINT)]
        private int $weekday,
        #[ORM\Column(name: 'start_time', length: 5)]
        private string $startTime,
        #[ORM\Column(name: 'duration_minutes', type: Types::SMALLINT)]
        private int $durationMinutes,
    ) {
    }

    public static function create(PermanentPlace $place, RegularScheduleDay $day): self
    {
        if (!$place->organizationId()->equals($day->organizationId())) {
            throw new \LogicException('Cannot create a permanent-place slot across organizations.');
        }

        return new self(new Ulid(), $place->organization(), $place->id(), $day->weekday(), $day->startTime(), $day->durationMinutes());
    }

    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function permanentPlaceId(): Ulid { return $this->permanentPlaceId; }
    public function weekday(): int { return $this->weekday; }
    public function startTime(): string { return $this->startTime; }
    public function durationMinutes(): int { return $this->durationMinutes; }
}
