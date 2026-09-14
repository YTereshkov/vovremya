<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Provider\WhatsApp;

final readonly class WhatsAppApiResponse
{
    /** @param array<string, mixed>|null $payload */
    public function __construct(public int $statusCode, public ?array $payload)
    {
    }
}
