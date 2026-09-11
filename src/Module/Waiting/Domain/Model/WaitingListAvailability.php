<?php

declare(strict_types=1);

namespace App\Module\Waiting\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'waiting_list_availability')]
#[ORM\UniqueConstraint(name: 'uniq_waiting_availability_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_waiting_availability_day', columns: ['organization_id', 'waiting_list_entry_id', 'weekday'])]
#[ORM\Index(name: 'idx_waiting_availability_match', columns: ['organization_id', 'weekday', 'start_time', 'end_time'])]
final class WaitingListAvailability implements OrganizationOwned
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(name: 'waiting_list_entry_id', type: 'ulid')]
        private Ulid $waitingListEntryId,
        #[ORM\Column(type: Types::SMALLINT)]
        private int $weekday,
        #[ORM\Column(name: 'start_time', length: 5)]
        private string $startTime,
        #[ORM\Column(name: 'end_time', length: 5, nullable: true)]
        private ?string $endTime,
    ) {
    }

    public static function create(WaitingListEntry $entry, int $weekday, string $startTime, ?string $endTime): self
    {
        if (1 > $weekday || 7 < $weekday) {
            throw new \InvalidArgumentException('День недели должен быть от 1 до 7.');
        }
        if (!self::validTime($startTime) || (null !== $endTime && (!self::validTime($endTime) || $endTime <= $startTime))) {
            throw new \InvalidArgumentException('Укажите корректный интервал ожидания.');
        }

        return new self(new Ulid(), $entry->organization(), $entry->id(), $weekday, $startTime, $endTime);
    }

    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function waitingListEntryId(): Ulid { return $this->waitingListEntryId; }
    public function weekday(): int { return $this->weekday; }
    public function startTime(): string { return $this->startTime; }
    public function endTime(): ?string { return $this->endTime; }

    private static function validTime(string $value): bool
    {
        return 1 === preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $value);
    }
}
