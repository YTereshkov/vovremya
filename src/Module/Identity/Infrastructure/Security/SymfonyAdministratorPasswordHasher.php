<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Security;

use App\Module\Identity\Application\AdministratorPasswordHasher;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;

final readonly class SymfonyAdministratorPasswordHasher implements AdministratorPasswordHasher
{
    public function __construct(private NativePasswordHasher $passwordHasher)
    {
    }

    public function hash(#[\SensitiveParameter] string $plainPassword): string
    {
        return $this->passwordHasher->hash($plainPassword);
    }

    public function verify(string $passwordHash, #[\SensitiveParameter] string $plainPassword): bool
    {
        return $this->passwordHasher->verify($passwordHash, $plainPassword);
    }
}
