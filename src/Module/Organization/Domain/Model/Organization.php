<?php

declare(strict_types=1);

namespace App\Module\Organization\Domain\Model;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'organizations')]
final class Organization
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\Column(length: 160)]
        private string $name,
        #[ORM\Column(length: 64)]
        private string $timezone,
        #[ORM\Column(name: 'default_channel', length: 16, options: ['default' => 'MAX'])]
        private string $defaultChannel,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(
        string $name,
        string $timezone,
        ?Ulid $id = null,
        ?DateTimeImmutable $createdAt = null,
    ): self {
        $name = trim($name);

        if ('' === $name) {
            throw new \InvalidArgumentException('Organization name cannot be empty.');
        }

        $nameLength = iconv_strlen($name, 'UTF-8');

        if (false === $nameLength || 160 < $nameLength) {
            throw new \InvalidArgumentException('Organization name cannot exceed 160 characters.');
        }

        $timezone = trim($timezone);

        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('Organization timezone is invalid.');
        }

        return new self(
            $id ?? new Ulid(),
            $name,
            $timezone,
            'MAX',
            $createdAt ?? new DateTimeImmutable(),
        );
    }

    public function id(): Ulid
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function defaultChannel(): string
    {
        return $this->defaultChannel;
    }

    public function changeDefaultChannel(string $provider): void
    {
        $provider = strtoupper(trim($provider));
        if (!in_array($provider, ['MAX', 'TELEGRAM', 'WHATSAPP'], true)) {
            throw new \InvalidArgumentException('Выберите поддерживаемый канал по умолчанию.');
        }
        $this->defaultChannel = $provider;
    }
}
