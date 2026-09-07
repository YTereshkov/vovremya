<?php

declare(strict_types=1);

namespace App\Module\Clients\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'contact_people')]
#[ORM\UniqueConstraint(name: 'uniq_contact_people_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_contact_people_owner_id', columns: ['organization_id', 'client_id', 'id'])]
#[ORM\Index(name: 'idx_contact_people_owner', columns: ['organization_id', 'client_id'])]
final class ContactPerson implements OrganizationOwned
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        Client $client,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(name: 'client_id', type: 'ulid')]
        private Ulid $clientId,
        #[ORM\Column(length: 160)]
        private string $name,
        #[ORM\Column(length: 32, nullable: true)]
        private ?string $phone,
    ) {
        if (!$client->organizationId()->equals($organization->id()) || !$client->id()->equals($clientId)) {
            throw new \InvalidArgumentException('Контактное лицо должно принадлежать клиенту.');
        }
        $this->change($name, $phone);
    }

    public static function create(Client $client, string $name, ?string $phone): self
    {
        return new self(new Ulid(), $client, $client->organization(), $client->id(), $name, $phone);
    }

    public function change(string $name, ?string $phone): void
    {
        $this->name = Client::requiredText($name, 160, 'Укажите имя контактного лица длиной до 160 символов.');
        $this->phone = Client::optionalText($phone, 32, 'Телефон не должен превышать 32 символа.');
    }

    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function clientId(): Ulid { return $this->clientId; }
    public function name(): string { return $this->name; }
    public function phone(): ?string { return $this->phone; }
}
