<?php

declare(strict_types=1);

namespace App\Module\Communications\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'administrator_notification_states')]
#[ORM\UniqueConstraint(name: 'uniq_admin_notification_states_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_admin_notification_states_admin', columns: ['organization_id', 'administrator_id'])]
final class AdministratorNotificationState implements OrganizationOwned
{
    private function __construct(
        #[ORM\Id, ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(name: 'administrator_id', type: 'ulid')]
        private Ulid $administratorId,
        #[ORM\Column(name: 'read_through', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $readThrough,
        #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function readThrough(Organization $organization, Ulid $administratorId, \DateTimeImmutable $at): self
    {
        $at = $at->setTimezone(new \DateTimeZone('UTC'));

        return new self(new Ulid(), $organization, $administratorId, $at, $at);
    }

    public function markRead(\DateTimeImmutable $at): void
    {
        $this->readThrough = $at->setTimezone(new \DateTimeZone('UTC'));
        $this->updatedAt = $this->readThrough;
    }

    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function administratorId(): Ulid { return $this->administratorId; }
    public function readThroughAt(): \DateTimeImmutable { return $this->readThrough; }
}
