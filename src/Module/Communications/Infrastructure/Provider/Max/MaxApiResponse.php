<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Provider\Max;

final readonly class MaxApiResponse
{
    /** @param array<string, mixed>|null $payload */
    public function __construct(
        public int $statusCode,
        public ?array $payload,
    ) {
    }
}
