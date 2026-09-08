<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

use App\Module\Communications\Domain\Model\CommunicationProvider;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('vovremya.communication_provider')]
interface ChannelProvider
{
    public function provider(): CommunicationProvider;

    public function capabilities(): ChannelCapabilities;

    public function send(OutboundMessageRequest $request): ProviderSendResult;

    public function authenticateWebhook(WebhookAuthenticationRequest $request): bool;

    /** @return list<WebhookEvent> */
    public function parseWebhook(array $payload): array;
}
