<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

interface AdministratorPasswordHasher
{
    public function hash(#[\SensitiveParameter] string $plainPassword): string;

    public function verify(string $passwordHash, #[\SensitiveParameter] string $plainPassword): bool;
}
