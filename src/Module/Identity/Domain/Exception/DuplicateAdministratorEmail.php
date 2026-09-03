<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain\Exception;

use DomainException;

final class DuplicateAdministratorEmail extends DomainException
{
    public function __construct(string $email, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('Administrator with email "%s" already exists.', $email), 0, $previous);
    }
}
