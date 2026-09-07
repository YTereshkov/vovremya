<?php

declare(strict_types=1);

namespace App\Shared\Domain\MultiTenancy;

final class CrossOrganizationAssociation extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Cross-organization associations are forbidden.');
    }
}
