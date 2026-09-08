<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

use App\Module\Communications\Domain\Model\CommunicationProvider;

final readonly class OutboundMessageRequest
{
    /** @param array<string, mixed> $buttons
     *  @param array<string, mixed> $metadata
     */
    public function __construct(
        public CommunicationProvider $provider,
        public string $recipientAddress,
        public string $body,
        public array $buttons = [],
        public array $metadata = [],
        public string $idempotencyKey = '',
    ) {
    }
}
