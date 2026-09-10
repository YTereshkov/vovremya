<?php

declare(strict_types=1);

namespace App\Module\Catalog\Application;

use Symfony\Component\Uid\Ulid;

interface ServiceNotificationTemplateResolver
{
    public function confirmationTemplate(Ulid $serviceId): ?string;
}
