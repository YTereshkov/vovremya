<?php

declare(strict_types=1);

namespace App\Tests\Module\Communications\Support;

use App\Module\Communications\Application\ChannelCapabilities;
use App\Module\Communications\Application\ChannelProvider;
use App\Module\Communications\Application\OutboundMessageRequest;
use App\Module\Communications\Application\ProviderSendResult;
use App\Module\Communications\Application\WebhookAuthenticationRequest;
use App\Module\Communications\Application\WebhookEvent;
use App\Module\Communications\Domain\Model\CommunicationProvider;

final class FakeChannelProvider implements ChannelProvider
{
    public const WEBHOOK_SECRET = 'communications-test-secret';

    /** @var list<OutboundMessageRequest> */
    public array $sent = [];

    public int $parsedWebhooks = 0;

    public ?\Throwable $sendFailure = null;

    public ?\Throwable $webhookFailure = null;

    public function __construct(private readonly CommunicationProvider $communicationProvider = CommunicationProvider::MAX)
    {
    }

    public function provider(): CommunicationProvider
    {
        return $this->communicationProvider;
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(true, false, false, false, true);
    }

    public function send(OutboundMessageRequest $request): ProviderSendResult
    {
        if (null !== $this->sendFailure) {
            throw $this->sendFailure;
        }

        $this->sent[] = $request;

        return new ProviderSendResult('provider-message-'.$request->idempotencyKey);
    }

    public function authenticateWebhook(WebhookAuthenticationRequest $request): bool
    {
        $provided = $request->headers['x-webhook-signature'][0] ?? '';
        $expected = hash_hmac('sha256', $request->rawBody, self::WEBHOOK_SECRET);

        return hash_equals($expected, $provided);
    }

    public function parseWebhook(array $payload): array
    {
        if (null !== $this->webhookFailure) {
            throw $this->webhookFailure;
        }

        ++$this->parsedWebhooks;

        return [new WebhookEvent($this->communicationProvider, (string) ($payload['id'] ?? 'event'), $payload)];
    }

    public static function signature(string $body): string
    {
        return hash_hmac('sha256', $body, self::WEBHOOK_SECRET);
    }
}
