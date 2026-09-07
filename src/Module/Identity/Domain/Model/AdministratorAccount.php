<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain\Model;

use App\Module\Organization\Domain\Model\Organization;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'administrator_accounts')]
#[ORM\UniqueConstraint(name: 'uniq_administrator_accounts_normalized_email', columns: ['normalized_email'])]
#[ORM\Index(name: 'idx_administrator_accounts_organization_id', columns: ['organization_id'])]
final class AdministratorAccount implements UserInterface, PasswordAuthenticatedUserInterface
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        private Ulid $id,
        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
        private Organization $organization,
        #[ORM\Column(length: 254)]
        private string $email,
        #[ORM\Column(name: 'normalized_email', length: 254)]
        private string $normalizedEmail,
        #[ORM\Column(name: 'password_hash', length: 255)]
        private string $passwordHash,
        #[ORM\Column(name: 'is_enabled', options: ['default' => true])]
        private bool $enabled,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(
        Organization $organization,
        string $email,
        string $passwordHash,
        ?Ulid $id = null,
        ?DateTimeImmutable $createdAt = null,
    ): self {
        $normalizedEmail = self::normalizeEmail($email);

        if ('' === $passwordHash) {
            throw new \InvalidArgumentException('Password hash cannot be empty.');
        }

        return new self(
            $id ?? new Ulid(),
            $organization,
            $normalizedEmail,
            $normalizedEmail,
            $passwordHash,
            true,
            $createdAt ?? new DateTimeImmutable(),
        );
    }

    public static function normalizeEmail(string $email): string
    {
        $normalizedEmail = strtolower(trim($email));

        if (254 < strlen($normalizedEmail) || false === filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Administrator email is invalid.');
        }

        return $normalizedEmail;
    }

    public function id(): Ulid
    {
        return $this->id;
    }

    public function organization(): Organization
    {
        return $this->organization;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function normalizedEmail(): string
    {
        return $this->normalizedEmail;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function getUserIdentifier(): string
    {
        return $this->normalizedEmail;
    }

    public function getRoles(): array
    {
        return ['ROLE_ADMIN'];
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function eraseCredentials(): void
    {
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
