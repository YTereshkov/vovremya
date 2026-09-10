<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'services')]
#[ORM\UniqueConstraint(name: 'uniq_services_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\Index(name: 'idx_services_active_name', columns: ['organization_id', 'name', 'id'], options: ['where' => '(deleted_at IS NULL)'])]
final class Service implements OrganizationOwned
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
        #[ORM\Column(name: 'default_duration_minutes', type: Types::SMALLINT)]
        private int $defaultDurationMinutes,
        #[ORM\Column(name: 'minimum_duration_minutes', type: Types::SMALLINT, nullable: true)]
        private ?int $minimumDurationMinutes,
        #[ORM\Column(name: 'maximum_duration_minutes', type: Types::SMALLINT, nullable: true)]
        private ?int $maximumDurationMinutes,
        #[ORM\Column(name: 'deleted_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?DateTimeImmutable $deletedAt,
        #[ORM\Column(name: 'confirmation_template', type: Types::TEXT, nullable: true)]
        private ?string $confirmationTemplate,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(
        Organization $organization,
        string $name,
        int $defaultDurationMinutes,
        ?int $minimumDurationMinutes,
        ?int $maximumDurationMinutes,
    ): self {
        $service = new self(
            new Ulid(),
            $organization,
            '',
            0,
            0,
            0,
            null,
            null,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        $service->change($name, $defaultDurationMinutes, $minimumDurationMinutes, $maximumDurationMinutes);

        return $service;
    }

    public function change(string $name, int $defaultDurationMinutes, ?int $minimumDurationMinutes, ?int $maximumDurationMinutes): void
    {
        $name = trim($name);
        $nameLength = iconv_strlen($name, 'UTF-8');

        if ('' === $name || false === $nameLength || 160 < $nameLength) {
            throw new \InvalidArgumentException('Укажите название услуги длиной до 160 символов.');
        }

        if (1 > $defaultDurationMinutes || 1440 < $defaultDurationMinutes
            || (null !== $minimumDurationMinutes && (1 > $minimumDurationMinutes || 1440 < $minimumDurationMinutes))
            || (null !== $maximumDurationMinutes && (1 > $maximumDurationMinutes || 1440 < $maximumDurationMinutes))) {
            throw new \InvalidArgumentException('Длительность должна быть от 1 до 1440 минут.');
        }

        if ((null !== $minimumDurationMinutes && $minimumDurationMinutes > $defaultDurationMinutes)
            || (null !== $maximumDurationMinutes && $defaultDurationMinutes > $maximumDurationMinutes)) {
            throw new \InvalidArgumentException('Длительность по умолчанию должна входить в диапазон от минимума до максимума.');
        }

        $this->name = $name;
        $this->defaultDurationMinutes = $defaultDurationMinutes;
        $this->minimumDurationMinutes = $minimumDurationMinutes;
        $this->maximumDurationMinutes = $maximumDurationMinutes;
    }

    public function delete(): void
    {
        $this->deletedAt ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function changeConfirmationTemplate(?string $template): void
    {
        $this->confirmationTemplate = null === $template ? null : $this->validateTemplate($template);
    }

    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function name(): string { return $this->name; }
    public function defaultDurationMinutes(): int { return $this->defaultDurationMinutes; }
    public function minimumDurationMinutes(): ?int { return $this->minimumDurationMinutes; }
    public function maximumDurationMinutes(): ?int { return $this->maximumDurationMinutes; }
    public function confirmationTemplate(): ?string { return $this->confirmationTemplate; }
    public function deletedAt(): ?DateTimeImmutable { return $this->deletedAt; }

    private function validateTemplate(string $template): string
    {
        $template = trim($template);
        if ('' === $template || 4000 < mb_strlen($template)) {
            throw new \InvalidArgumentException('Шаблон должен содержать от 1 до 4000 символов.');
        }
        preg_match_all('/\{([a-z_]+)\}/u', $template, $matches);
        $unknown = array_diff(array_unique($matches[1] ?? []), ['date', 'time', 'service', 'client_name', 'contact_name']);
        if ([] !== $unknown) {
            throw new \InvalidArgumentException('Шаблон содержит неизвестную переменную: {'.reset($unknown).'}.');
        }

        return $template;
    }
}
