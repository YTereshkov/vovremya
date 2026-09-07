<?php

declare(strict_types=1);

namespace App\Module\Organization\Application\Exception;

final class MissingOrganizationContext extends \LogicException
{
    public function __construct()
    {
        parent::__construct('Organization context is not available.');
    }
}
