<?php

declare(strict_types=1);

namespace App\Module\Organization\Application\Exception;

final class CrossOrganizationContext extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Organization context cannot switch tenants inside an active scope.');
    }
}
