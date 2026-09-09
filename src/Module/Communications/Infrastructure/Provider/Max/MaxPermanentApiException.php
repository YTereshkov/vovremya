<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Provider\Max;

final class MaxPermanentApiException extends \DomainException
{
    public function __construct(string $message, public readonly int $statusCode, public readonly bool $transient = false)
    {
        parent::__construct($message);
    }
}
