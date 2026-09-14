<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Provider\WhatsApp;

class WhatsAppApiException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode, public readonly bool $transient)
    {
        parent::__construct($message);
    }
}
