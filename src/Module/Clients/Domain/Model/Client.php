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
#[ORM\Table(name: 'clients')]
#[ORM\UniqueConstraint(name: 'uniq_clients_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\Index(name: 'idx_clients_tenant_name', columns: ['organization_id', 'name', 'id'])]
final class Client implements OrganizationOwned
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(length: 160)]
        private string $name,
        #[ORM\Column(name: 'client_type', length: 16)]
        private string $type,
        #[ORM\Column(length: 32, nullable: true)]
        private ?string $phone,
        #[ORM\Column(length: 300, nullable: true)]
        private ?string $note,
        #[ORM\Column(name: 'primary_channel_id', type: 'ulid', nullable: true)]
        private ?Ulid $primaryChannelId,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(Organization $organization, string $name, string $type, ?string $phone, ?string $note): self
    {
        $client = new self(new Ulid(), $organization, '', '', null, null, null, new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $client->change($name, $type, $phone, $note);

        return $client;
    }

    public function change(string $name, string $type, ?string $phone, ?string $note): void
    {
        $this->name = self::requiredText($name, 160, 'Укажите имя клиента длиной до 160 символов.');
        if (!in_array($type, ['CHILD', 'ADULT'], true)) {
            throw new \InvalidArgumentException('Выберите тип клиента: ребёнок или взрослый.');
        }
        $this->type = $type;
        $this->phone = self::optionalText($phone, 32, 'Телефон не должен превышать 32 символа.');
        $this->note = self::optionalText($note, 300, 'Заметка не должна превышать 300 символов.');
    }

    public function selectPrimaryChannel(?ChannelConnection $channel): void
    {
        if (null !== $channel) {
            OrganizationIsolation::assertCanAssociate($this, $channel);
            if (!$this->id->equals($channel->clientId())) {
                throw new \InvalidArgumentException('Основной канал должен принадлежать этому клиенту.');
            }
        }
        $this->primaryChannelId = $channel?->id();
    }

    public function id(): Ulid { return $this->id; }
    public function organization(): Organization { return $this->organization; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function name(): string { return $this->name; }
    public function type(): string { return $this->type; }
    public function phone(): ?string { return $this->phone; }
    public function note(): ?string { return $this->note; }
    public function primaryChannelId(): ?Ulid { return $this->primaryChannelId; }

    public static function requiredText(string $value, int $maximum, string $message): string
    {
        $value = trim($value);
        $length = iconv_strlen($value, 'UTF-8');
        if ('' === $value || false === $length || $maximum < $length) {
            throw new \InvalidArgumentException($message);
        }

        return $value;
    }

    public static function optionalText(?string $value, int $maximum, string $message): ?string
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        return self::requiredText($value, $maximum, $message);
    }
}
