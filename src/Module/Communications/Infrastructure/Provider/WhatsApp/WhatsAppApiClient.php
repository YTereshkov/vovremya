<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Provider\WhatsApp;

interface WhatsAppApiClient
{
    /** @param array<string, mixed> $payload */
    public function sendMessage(string $accessToken, string $phoneNumberId, array $payload): WhatsAppApiResponse;
}
