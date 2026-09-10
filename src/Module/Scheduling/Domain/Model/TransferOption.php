<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'transfer_options')]
#[ORM\UniqueConstraint(name: 'uniq_transfer_options_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_transfer_options_interval', columns: ['organization_id', 'transfer_request_id', 'starts_at'])]
final class TransferOption implements OrganizationOwned
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(name: 'transfer_request_id', type: 'ulid')]
        private Ulid $transferRequestId,
        #[ORM\Column(name: 'starts_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $startsAt,
        #[ORM\Column(name: 'ends_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $endsAt,
        #[ORM\Column(name: 'token_hash', length: 64)]
        private string $tokenHash,
        #[ORM\Column(name: 'selected_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $selectedAt,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public static function offer(TransferRequest $request, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, string $token, \DateTimeImmutable $now): self
    {
        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('Окончание варианта должно быть позже начала.');
        }
        if (16 > strlen($token)) {
            throw new \InvalidArgumentException('Transfer option token is too short.');
        }
        $utc = new \DateTimeZone('UTC');

        return new self(
            new Ulid(),
            $request->organization(),
            $request->id(),
            $startsAt->setTimezone($utc),
            $endsAt->setTimezone($utc),
            hash('sha256', $token),
            null,
            $now->setTimezone($utc),
        );
    }

    public function authorize(string $token): bool
    {
        return null === $this->selectedAt && hash_equals($this->tokenHash, hash('sha256', $token));
    }

    public function select(\DateTimeImmutable $now): void
    {
        if (null !== $this->selectedAt) {
            throw new \DomainException('Вариант переноса уже выбран.');
        }
        $this->selectedAt = $now->setTimezone(new \DateTimeZone('UTC'));
    }

    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function transferRequestId(): Ulid { return $this->transferRequestId; }
    public function startsAt(): \DateTimeImmutable { return $this->startsAt; }
    public function endsAt(): \DateTimeImmutable { return $this->endsAt; }
    public function selectedAt(): ?\DateTimeImmutable { return $this->selectedAt; }
}
