<?php

declare(strict_types=1);

namespace App\Module\Communications\Application\Message;

use App\Module\Clients\Application\ChannelConnectionResolver;
use App\Module\Communications\Application\ChannelProviderRegistry;
use App\Module\Communications\Application\CommunicationStore;
use App\Module\Communications\Application\OutboundMessageRequest;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendOutboundMessageHandler
{
    public function __construct(
        private CommunicationStore $store,
        private ChannelProviderRegistry $providers,
        private ChannelConnectionResolver $connections,
    ) {
    }

    public function __invoke(SendOutboundMessage $message): void
    {
        $outbound = $this->store->claimOutbound($message->outboundMessageId());
        if (null === $outbound) {
            return;
        }

        try {
            $channelId = $outbound->channelConnectionId();
            $connection = null === $channelId ? null : $this->connections->findChannelForTenant($channelId);
            if (null === $connection || !$connection->isActive()) {
                throw new \DomainException('Канал получателя не найден или отключён.');
            }
            if ($connection->provider() !== $outbound->provider()->value || $connection->address() !== $outbound->recipientAddress()) {
                throw new \DomainException('Канал получателя изменился или не соответствует сообщению.');
            }
            $result = $this->providers->get($outbound->provider())->send(new OutboundMessageRequest(
                $outbound->provider(),
                $outbound->recipientAddress(),
                $outbound->body(),
                $outbound->buttons(),
                $outbound->metadata(),
                $outbound->id()->toRfc4122(),
            ));
            $outbound->markSent($result->providerMessageId);
            $this->store->save($outbound);
        } catch (\DomainException $exception) {
            $outbound->fail($exception->getMessage());
            $this->store->save($outbound);
        } catch (\Throwable $exception) {
            if ($outbound->attempts() >= 3) {
                $outbound->fail($exception->getMessage());
            } else {
                $outbound->retry($exception->getMessage());
            }
            $this->store->save($outbound);
            throw $exception;
        }
    }
}
