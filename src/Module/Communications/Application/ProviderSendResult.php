<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

final readonly class ProviderSendResult
{
    public function __construct(public ?string $providerMessageId = null)
    {
    }
}
