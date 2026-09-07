<?php

declare(strict_types=1);

namespace App\Module\Organization\Application;

final class OrganizationPermission
{
    public const string VIEW = 'organization_owned.view';
    public const string EDIT = 'organization_owned.edit';

    private function __construct()
    {
    }
}
