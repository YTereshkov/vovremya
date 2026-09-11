<?php

declare(strict_types=1);

namespace App\Module\Clients\Application;

use Symfony\Component\Uid\Ulid;

final readonly class WaitingClientProfile
{
    public function __construct(public Ulid $id, public string $name)
    {
    }
}
