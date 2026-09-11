<?php

declare(strict_types=1);

namespace App\Module\Waiting\Domain\Model;

use App\Module\Clients\Domain\Model\Client;
use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'waiting_list_entries')]
#[ORM\UniqueConstraint(name: 'uniq_waiting_entries_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_waiting_entries_active_client', columns: ['organization_id', 'client_id'], options: ['where' => '(active = true)'])]
#[ORM\Index(name: 'idx_waiting_entries_matching', columns: ['organization_id', 'service_id', 'active', 'ready_for_one_off', 'effective_from'])]
final class WaitingListEntry implements OrganizationOwned
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
        #[ORM\Column(name: 'service_id', type: 'ulid')]
        private Ulid $serviceId,
        #[ORM\Column(name: 'specialist_id', type: 'ulid', nullable: true)]
        private ?Ulid $specialistId,
        #[ORM\Column(name: 'required_frequency', type: Types::SMALLINT)]
        private int $requiredFrequency,
        #[ORM\Column(name: 'ready_for_one_off')]
        private bool $readyForOneOff,
        #[ORM\Column(name: 'effective_from', type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $effectiveFrom,
        #[ORM\Column(length: 300, nullable: true)]
        private ?string $comment,
        #[ORM\Column]
        private bool $active,
        #[ORM\Column(name: 'ended_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $endedAt,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(Client $client, Ulid $serviceId, ?Ulid $specialistId, int $requiredFrequency, bool $readyForOneOff, \DateTimeImmutable $effectiveFrom, ?string $comment, \DateTimeImmutable $now): self
    {
        $entry = new self(
            new Ulid(),
            $client->organization(),
            $client->id(),
            $serviceId,
            $specialistId,
            1,
            false,
            self::date($effectiveFrom),
            null,
            true,
            null,
            self::instant($now),
            self::instant($now),
        );
        $entry->change($serviceId, $specialistId, $requiredFrequency, $readyForOneOff, $comment, $now);

        return $entry;
    }

    public function change(Ulid $serviceId, ?Ulid $specialistId, int $requiredFrequency, bool $readyForOneOff, ?string $comment, \DateTimeImmutable $now): void
    {
        if (!$this->active) {
            throw new \DomainException('Завершённое ожидание нельзя изменить.');
        }
        if (1 > $requiredFrequency || 7 < $requiredFrequency) {
            throw new \InvalidArgumentException('Частота должна быть от одного до семи занятий в неделю.');
        }
        $comment = null === $comment ? null : trim($comment);
        if ('' === $comment) {
            $comment = null;
        }
        if (null !== $comment && 300 < mb_strlen($comment)) {
            throw new \InvalidArgumentException('Комментарий не должен превышать 300 символов.');
        }
        $this->serviceId = $serviceId;
        $this->specialistId = $specialistId;
        $this->requiredFrequency = $requiredFrequency;
        $this->readyForOneOff = $readyForOneOff;
        $this->comment = $comment;
        $this->updatedAt = self::instant($now);
    }

    public function end(\DateTimeImmutable $now): void
    {
        if (!$this->active) {
            return;
        }
        $this->active = false;
        $this->endedAt = self::instant($now);
        $this->updatedAt = self::instant($now);
    }

    public function id(): Ulid { return $this->id; }
    public function organization(): Organization { return $this->organization; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function clientId(): Ulid { return $this->clientId; }
    public function serviceId(): Ulid { return $this->serviceId; }
    public function specialistId(): ?Ulid { return $this->specialistId; }
    public function requiredFrequency(): int { return $this->requiredFrequency; }
    public function readyForOneOff(): bool { return $this->readyForOneOff; }
    public function effectiveFrom(): \DateTimeImmutable { return $this->effectiveFrom; }
    public function comment(): ?string { return $this->comment; }
    public function active(): bool { return $this->active; }

    private static function date(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'));
    }

    private static function instant(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->setTimezone(new \DateTimeZone('UTC'));
    }
}
