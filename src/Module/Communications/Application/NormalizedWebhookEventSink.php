<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

use Symfony\Component\Uid\Ulid;

interface NormalizedWebhookEventSink
{
    public function record(Ulid $organizationId, ?Ulid $channelConnectionId, WebhookEvent $event): ?Ulid;
}
