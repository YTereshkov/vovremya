<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Provider\Telegram;

final class TelegramTransientApiException extends TelegramApiException
{
    public function __construct(string $message, int $statusCode)
    {
        parent::__construct($message, $statusCode, true);
    }
}
