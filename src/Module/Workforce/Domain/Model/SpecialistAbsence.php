<?php

declare(strict_types=1);

namespace App\Module\Workforce\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'specialist_absences')]
#[ORM\UniqueConstraint(name: 'uniq_specialist_absences_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\Index(name: 'idx_specialist_absences_period', columns: ['organization_id', 'specialist_id', 'ends_on', 'starts_on'])]
final class SpecialistAbsence implements OrganizationOwned
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
        #[ORM\Column(name: 'absence_type', length: 24, enumType: SpecialistAbsenceType::class)]
        private SpecialistAbsenceType $type,
        #[ORM\Column(name: 'starts_on', type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $startsOn,
        #[ORM\Column(name: 'ends_on', type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $endsOn,
        #[ORM\Column(length: 1000, nullable: true)]
        private ?string $comment,
        #[ORM\Column(name: 'notify_clients')]
        private bool $notifyClients,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(
        Specialist $specialist,
        SpecialistAbsenceType $type,
        \DateTimeImmutable $startsOn,
        \DateTimeImmutable $endsOn,
        ?string $comment,
        bool $notifyClients,
        \DateTimeImmutable $now,
    ): self {
        $start = self::date($startsOn);
        $end = self::date($endsOn);
        if ($end < $start) {
            throw new \InvalidArgumentException('Дата окончания должна быть не раньше даты начала.');
        }
        $comment = null === $comment ? null : trim($comment);
        if ('' === $comment) {
            $comment = null;
        }
        if (null !== $comment && 1000 < mb_strlen($comment)) {
            throw new \InvalidArgumentException('Комментарий не должен превышать 1000 символов.');
        }

        return new self(new Ulid(), $specialist->organization(), $specialist->id(), $type, $start, $end, $comment, $notifyClients, $now->setTimezone(new \DateTimeZone('UTC')));
    }

    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function specialistId(): Ulid { return $this->specialistId; }
    public function type(): SpecialistAbsenceType { return $this->type; }
    public function startsOn(): \DateTimeImmutable { return $this->startsOn; }
    public function endsOn(): \DateTimeImmutable { return $this->endsOn; }
    public function comment(): ?string { return $this->comment; }
    public function notifyClients(): bool { return $this->notifyClients; }

    private static function date(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'));
    }
}
