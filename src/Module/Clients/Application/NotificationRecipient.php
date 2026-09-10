<?php

declare(strict_types=1);

namespace App\Module\Clients\Application;

use Symfony\Component\Uid\Ulid;

final readonly class NotificationRecipient
{
    public function __construct(
        public Ulid $channelConnectionId,
        public string $provider,
        public string $address,
        public string $clientName,
        public ?string $contactName,
    ) {
    }
}
