<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Provider\WhatsApp;

final class WhatsAppTransientApiException extends WhatsAppApiException
{
    public function __construct(string $message, int $statusCode)
    {
        parent::__construct($message, $statusCode, true);
    }
}
