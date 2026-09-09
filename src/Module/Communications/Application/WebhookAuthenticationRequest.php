<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

final readonly class WebhookAuthenticationRequest
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public string $rawBody,
        public array $headers,
        public ?string $expectedSecretHash = null,
    ) {
    }
}
