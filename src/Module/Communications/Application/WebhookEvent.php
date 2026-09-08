<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

use App\Module\Communications\Domain\Model\CommunicationProvider;

final readonly class WebhookEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public CommunicationProvider $provider,
        public string $externalEventId,
        public array $payload,
    ) {
    }
}
