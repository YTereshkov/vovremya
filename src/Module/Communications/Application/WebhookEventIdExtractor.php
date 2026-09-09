<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

interface WebhookEventIdExtractor
{
    /** @return list<string> */
    public function extractWebhookEventIds(array $payload): array;
}
