<?php

declare(strict_types=1);

namespace App\Module\Communications\Application\Message;

use App\Module\Communications\Application\ChannelProviderRegistry;
use App\Module\Communications\Application\CommunicationStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessWebhookInboxHandler
{
    public function __construct(
        private CommunicationStore $store,
        private ChannelProviderRegistry $providers,
    ) {
    }

    public function __invoke(ProcessWebhookInbox $message): void
    {
        $inbox = $this->store->claimWebhook($message->inboxId());
        if (null === $inbox) {
            return;
        }

        try {
            $this->providers->get($inbox->provider())->parseWebhook($inbox->payload());
            $inbox->markProcessed();
            $this->store->save($inbox);
        } catch (\DomainException $exception) {
            $inbox->markFailed($exception->getMessage());
            $this->store->save($inbox);
        } catch (\Throwable $exception) {
            if ($inbox->attempts() >= 3) {
                $inbox->markFailed($exception->getMessage());
            } else {
                $inbox->retry($exception->getMessage());
            }
            $this->store->save($inbox);
            throw $exception;
        }
    }
}
