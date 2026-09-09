<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Provider\Max;

interface MaxApiClient
{
    /** @param array<string, mixed> $payload */
    public function sendMessage(
        string $accessToken,
        string $recipientAddress,
        array $payload,
        string $idempotencyKey,
        ?string $chatId = null,
    ): MaxApiResponse;
}
