<?php

declare(strict_types=1);

namespace App\Module\Clients\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'client_absences')]
#[ORM\UniqueConstraint(name: 'uniq_client_absences_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\Index(name: 'idx_client_absences_period', columns: ['organization_id', 'client_id', 'ends_on', 'starts_on'])]
final class ClientAbsence implements OrganizationOwned
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(name: 'client_id', type: 'ulid')]
        private Ulid $clientId,
        #[ORM\Column(name: 'starts_on', type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $startsOn,
        #[ORM\Column(name: 'ends_on', type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $endsOn,
        #[ORM\Column(length: 160, nullable: true)]
        private ?string $reason,
        #[ORM\Column(length: 32, enumType: ClientAbsenceMode::class)]
        private ClientAbsenceMode $mode,
        #[ORM\Column(name: 'create_free_windows')]
        private bool $createFreeWindows,
        #[ORM\Column(name: 'notify_client')]
        private bool $notifyClient,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(
        Client $client,
        \DateTimeImmutable $startsOn,
        \DateTimeImmutable $endsOn,
        ?string $reason,
        ClientAbsenceMode $mode,
        bool $createFreeWindows,
        bool $notifyClient,
        \DateTimeImmutable $now,
    ): self {
        $start = self::date($startsOn);
        $end = self::date($endsOn);
        if ($end < $start) {
            throw new \InvalidArgumentException('Дата окончания должна быть не раньше даты начала.');
        }
        $reason = null === $reason ? null : trim($reason);
        if ('' === $reason) {
            $reason = null;
        }
        if (null !== $reason && 160 < mb_strlen($reason)) {
            throw new \InvalidArgumentException('Причина не должна превышать 160 символов.');
        }
        if (ClientAbsenceMode::ReleasePermanentPlace === $mode && $createFreeWindows) {
            throw new \InvalidArgumentException('Разовые окна создаются только при сохранении постоянного места.');
        }

        return new self(new Ulid(), $client->organization(), $client->id(), $start, $end, $reason, $mode, $createFreeWindows, $notifyClient, $now->setTimezone(new \DateTimeZone('UTC')));
    }

    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function clientId(): Ulid { return $this->clientId; }
    public function startsOn(): \DateTimeImmutable { return $this->startsOn; }
    public function endsOn(): \DateTimeImmutable { return $this->endsOn; }
    public function reason(): ?string { return $this->reason; }
    public function mode(): ClientAbsenceMode { return $this->mode; }
    public function createFreeWindows(): bool { return $this->createFreeWindows; }
    public function notifyClient(): bool { return $this->notifyClient; }

    private static function date(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'));
    }
}
