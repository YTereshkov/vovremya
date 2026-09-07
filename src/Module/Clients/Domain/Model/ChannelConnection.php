<?php

declare(strict_types=1);

namespace App\Module\Clients\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'channel_connections')]
#[ORM\UniqueConstraint(name: 'uniq_channel_connections_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_channel_connections_owner_id', columns: ['organization_id', 'client_id', 'id'])]
#[ORM\Index(name: 'idx_channel_connections_owner', columns: ['organization_id', 'client_id'])]
final class ChannelConnection implements OrganizationOwned
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
        #[ORM\Column(name: 'contact_person_id', type: 'ulid', nullable: true)]
        private ?Ulid $contactPersonId,
        #[ORM\Column(length: 16)]
        private string $provider,
        #[ORM\Column(length: 254)]
        private string $address,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(Client $client, ?ContactPerson $contactPerson, string $provider, string $address): self
    {
        if (null !== $contactPerson) {
            OrganizationIsolation::assertCanAssociate($client, $contactPerson);
            if (!$client->id()->equals($contactPerson->clientId())) {
                throw new \InvalidArgumentException('Получатель канала должен принадлежать клиенту.');
            }
        }

        $connection = new self(
            new Ulid(),
            $client->organization(),
            $client->id(),
            $contactPerson?->id(),
            '',
            '',
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        $connection->change($provider, $address);

        return $connection;
    }

    public function change(string $provider, string $address): void
    {
        if (!in_array($provider, ['MAX', 'TELEGRAM', 'WHATSAPP'], true)) {
            throw new \InvalidArgumentException('Выберите поддерживаемый канал связи.');
        }
        $this->provider = $provider;
        $this->address = Client::requiredText($address, 254, 'Укажите адрес или телефон канала длиной до 254 символов.');
    }

    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function clientId(): Ulid { return $this->clientId; }
    public function contactPersonId(): ?Ulid { return $this->contactPersonId; }
    public function provider(): string { return $this->provider; }
    public function address(): string { return $this->address; }
}
