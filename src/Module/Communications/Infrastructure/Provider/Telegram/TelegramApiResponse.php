<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Provider\Telegram;

final readonly class TelegramApiResponse
{
    /** @param array<string, mixed>|null $payload */
    public function __construct(public int $statusCode, public ?array $payload)
    {
    }
}
