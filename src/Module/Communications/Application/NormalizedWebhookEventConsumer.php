<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

use App\Module\Communications\Domain\Model\NormalizedWebhookEvent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('vovremya.normalized_webhook_event_consumer')]
interface NormalizedWebhookEventConsumer
{
    public function consume(NormalizedWebhookEvent $event): void;
}
