<?php

declare(strict_types=1);

namespace App\Module\Communications\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'organization_message_templates')]
#[ORM\UniqueConstraint(name: 'uniq_message_templates_tenant_id', columns: ['organization_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_message_templates_type', columns: ['organization_id', 'type'])]
final class OrganizationMessageTemplate implements OrganizationOwned
{
    private const VARIABLES = ['date', 'time', 'service', 'client_name', 'contact_name'];

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(enumType: MessageTemplateType::class, length: 24)]
        private MessageTemplateType $type,
        #[ORM\Column(type: Types::TEXT)]
        private string $body,
        #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(Organization $organization, MessageTemplateType $type, string $body): self
    {
        $template = new self(new Ulid(), $organization, $type, '', new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $template->changeBody($body);

        return $template;
    }

    public function changeBody(string $body): void
    {
        $this->body = self::validateBody($body);
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function validateBody(string $body): string
    {
        $body = trim($body);
        $length = mb_strlen($body);
        if ('' === $body || 4000 < $length) {
            throw new \InvalidArgumentException('Шаблон должен содержать от 1 до 4000 символов.');
        }

        preg_match_all('/\{([a-z_]+)\}/u', $body, $matches);
        $unknown = array_diff(array_unique($matches[1] ?? []), self::VARIABLES);
        if ([] !== $unknown) {
            throw new \InvalidArgumentException('Шаблон содержит неизвестную переменную: {'.reset($unknown).'}.');
        }
        if (preg_match('/\{[^{}]*\{|\}[^{}]*\}|\{[^}]*$|^[^{]*\}/u', $body)) {
            throw new \InvalidArgumentException('Проверьте синтаксис переменных шаблона.');
        }

        return $body;
    }

    public function id(): Ulid { return $this->id; }
    public function organizationId(): Ulid { return $this->organization->id(); }
    public function type(): MessageTemplateType { return $this->type; }
    public function body(): string { return $this->body; }
}
