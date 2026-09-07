<?php

declare(strict_types=1);

namespace App\Module\Workforce\Domain\Model;

use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'specialists')]
#[ORM\UniqueConstraint(name: 'uniq_specialists_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_specialists_administrator', columns: ['organization_id', 'administrator_id'])]
final class Specialist implements OrganizationOwned
{
    #[ORM\Column(type: 'jsonb')]
    private array $weeklyHours;

    #[ORM\Column(type: 'ulid', nullable: true)]
    private ?Ulid $administratorId = null;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(length: 100)]
    private string $specialization;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Id, ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        string $name,
        string $specialization,
    ) {
        $this->rename($name, $specialization);
        $this->weeklyHours = SpecialistWeeklyHours::emptyWeek();
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function rename(string $name, string $specialization): void
    {
        $name = trim($name);
        $specialization = trim($specialization);
        if ('' === $name || false === ($length = iconv_strlen($name, 'UTF-8')) || $length > 160) {
            throw new \InvalidArgumentException('Укажите имя специалиста длиной до 160 символов.');
        }
        if ('' === $specialization || false === ($length = iconv_strlen($specialization, 'UTF-8')) || $length > 100) {
            throw new \InvalidArgumentException('Укажите специализацию длиной до 100 символов.');
        }
        $this->name = $name;
        $this->specialization = $specialization;
    }

    public function linkAdministrator(?AdministratorAccount $administrator): void
    {
        if (null !== $administrator) {
            OrganizationIsolation::assertCanAssociate($this, $administrator);
        }
        $this->administratorId = $administrator?->id();
    }

    public function setWeeklyHours(array $days): void { $this->weeklyHours = SpecialistWeeklyHours::validate($days); }
    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function organization(): Organization { return $this->organization; }
    public function administratorId(): ?Ulid { return $this->administratorId; }
    public function name(): string { return $this->name; }
    public function specialization(): string { return $this->specialization; }
    public function weeklyHours(): array { return $this->weeklyHours; }
}
