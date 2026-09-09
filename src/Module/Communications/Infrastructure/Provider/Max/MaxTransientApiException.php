<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Provider\Max;

final class MaxTransientApiException extends MaxApiException
{
    public function __construct(string $message, int $statusCode)
    {
        parent::__construct($message, $statusCode, true);
    }
}
