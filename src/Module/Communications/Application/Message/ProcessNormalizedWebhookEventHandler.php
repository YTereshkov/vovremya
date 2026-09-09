<?php

declare(strict_types=1);

namespace App\Module\Communications\Application\Message;

use App\Module\Communications\Application\NormalizedWebhookEventConsumer;
use App\Module\Communications\Application\NormalizedWebhookEventStore;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessNormalizedWebhookEventHandler
{
    /** @param iterable<NormalizedWebhookEventConsumer> $consumers */
    public function __construct(
        private NormalizedWebhookEventStore $events,
        #[AutowireIterator('vovremya.normalized_webhook_event_consumer')]
        private iterable $consumers,
    ) {
    }

    public function __invoke(ProcessNormalizedWebhookEvent $message): void
    {
        $event = $this->events->claim($message->eventId());
        if (null === $event) {
            return;
        }
        try {
            $this->events->completeAtomically($event, function () use ($event): void {
                foreach ($this->consumers as $consumer) {
                    $consumer->consume($event);
                }
            });
        } catch (\Throwable $exception) {
            $this->events->recordProcessingFailure($event->id(), $event->attempts(), $exception->getMessage());
            throw $exception;
        }
    }
}
