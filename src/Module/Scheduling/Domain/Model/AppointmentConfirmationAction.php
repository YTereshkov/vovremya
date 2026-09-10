<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'appointment_confirmation_actions')]
#[ORM\UniqueConstraint(name: 'uniq_confirmation_actions_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_confirmation_actions_token', columns: ['organization_id', 'token_hash'])]
final class AppointmentConfirmationAction implements OrganizationOwned
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(name: 'confirmation_request_id', type: 'ulid')]
        private Ulid $confirmationRequestId,
        #[ORM\Column(name: 'action_type', length: 24, enumType: ConfirmationActionType::class)]
        private ConfirmationActionType $type,
        #[ORM\Column(name: 'token_hash', length: 64)]
        private string $tokenHash,
        #[ORM\Column(name: 'consumed_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $consumedAt,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(AppointmentConfirmationRequest $request, ConfirmationActionType $type, string $token, \DateTimeImmutable $now): self
    {
        if (16 > strlen($token)) {
            throw new \InvalidArgumentException('Confirmation action token is too short.');
        }

        return new self(
            new Ulid(),
            $request->organization(),
            $request->id(),
            $type,
            hash('sha256', $token),
            null,
            $now->setTimezone(new \DateTimeZone('UTC')),
        );
    }

    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function confirmationRequestId(): Ulid { return $this->confirmationRequestId; }
    public function type(): ConfirmationActionType { return $this->type; }
}
