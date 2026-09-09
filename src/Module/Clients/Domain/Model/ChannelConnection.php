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
#[ORM\UniqueConstraint(name: 'uniq_channel_connections_webhook_route', columns: ['provider', 'webhook_routing_key'])]
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
        #[ORM\Column(name: 'webhook_routing_key', length: 64)]
        private string $webhookRoutingKey,
        #[ORM\Column(name: 'webhook_secret_hash', length: 128, nullable: true)]
        private ?string $webhookSecretHash,
        #[ORM\Column(options: ['default' => true])]
        private bool $active,
        #[ORM\Column(options: ['default' => false])]
        private bool $verified,
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
            bin2hex(random_bytes(24)),
            null,
            false,
            false,
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
        $address = Client::requiredText($address, 254, 'Укажите адрес или телефон канала длиной до 254 символов.');
        if ('' !== $this->provider && ($this->provider !== $provider || $this->address !== $address)) {
            // Changing the external recipient invalidates prior ownership proof.
            $this->active = false;
            $this->verified = false;
            $this->webhookSecretHash = null;
        }
        $this->provider = $provider;
        $this->address = $address;
    }

    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function clientId(): Ulid { return $this->clientId; }
    public function contactPersonId(): ?Ulid { return $this->contactPersonId; }
    public function provider(): string { return $this->provider; }
    public function address(): string { return $this->address; }
    public function webhookRoutingKey(): string { return $this->webhookRoutingKey; }
    public function webhookSecretHash(): ?string { return $this->webhookSecretHash; }
    public function isActive(): bool { return $this->active; }
    public function isPendingActivation(): bool { return !$this->verified; }
    public function state(): string { return $this->isPendingActivation() ? 'PENDING' : ($this->active ? 'ACTIVE' : 'DISABLED'); }

    public function configureWebhookSecret(string $secret): void
    {
        $secret = trim($secret);
        if ('' === $secret) {
            throw new \InvalidArgumentException('Webhook secret не может быть пустым.');
        }
        $this->webhookSecretHash = hash('sha256', $secret);
    }

    public function verifyWebhookSecret(string $secret): bool
    {
        return null !== $this->webhookSecretHash && hash_equals($this->webhookSecretHash, hash('sha256', $secret));
    }

    public function deactivate(): void { $this->active = false; }
    public function activate(): void { $this->active = true; $this->verified = true; }
}
