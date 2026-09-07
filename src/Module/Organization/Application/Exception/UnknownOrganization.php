<?php

declare(strict_types=1);

namespace App\Module\Organization\Application\Exception;

final class UnknownOrganization extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Organization does not exist.');
    }
}
