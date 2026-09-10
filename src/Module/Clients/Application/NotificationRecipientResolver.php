<?php

declare(strict_types=1);

namespace App\Module\Clients\Application;

use Symfony\Component\Uid\Ulid;

interface NotificationRecipientResolver
{
    public function primaryForClient(Ulid $clientId): ?NotificationRecipient;

    public function byChannel(Ulid $clientId, Ulid $channelConnectionId): ?NotificationRecipient;
}
