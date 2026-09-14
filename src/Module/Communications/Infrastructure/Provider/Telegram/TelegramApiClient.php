<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Provider\Telegram;

interface TelegramApiClient
{
    /** @param array<string, mixed> $payload */
    public function sendMessage(string $botToken, array $payload): TelegramApiResponse;
}
