<?php

declare(strict_types=1);

namespace App\Module\Communications\Application\Message;

use App\Module\Clients\Application\ChannelConnectionResolver;
use App\Module\Communications\Application\NormalizedWebhookEventSink;
use App\Module\Communications\Application\ChannelProviderRegistry;
use App\Module\Communications\Application\CommunicationStore;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessWebhookInboxHandler
{
    public function __construct(
        private CommunicationStore $store,
        private ChannelProviderRegistry $providers,
        private ChannelConnectionResolver $connections,
        private NormalizedWebhookEventSink $normalizedEvents,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(ProcessWebhookInbox $message): void
    {
        $inbox = $this->store->claimWebhook($message->inboxId());
        if (null === $inbox) {
            return;
        }

        try {
            $connection = null;
            if (null !== $inbox->channelConnectionId()) {
                $connection = $this->connections->findChannelForTenant($inbox->channelConnectionId());
                if (null === $connection || $connection->provider() !== $inbox->provider()->value) {
                    throw new \DomainException('Канал webhook не принадлежит этой организации.');
                }
            }
            $events = $this->providers->get($inbox->provider())->parseWebhook($inbox->payload());
            if (null !== $connection && $connection->isPendingActivation()) {
                $activation = array_values(array_filter($events, static fn ($event): bool => 'ACTIVATION' === ($event->payload['kind'] ?? null)));
                $verified = false;
                foreach ($activation as $event) {
                    if (is_string($event->payload['userId'] ?? null) && hash_equals($connection->address(), $event->payload['userId'])) {
                        $verified = true;
                        break;
                    }
                }
                if (!$verified) {
                    throw new \DomainException('Канал webhook ещё не подтверждён получателем.');
                }
                $connection->activate();
                $this->store->save($connection);
            } elseif (null !== $connection && !$connection->isActive()) {
                throw new \DomainException('Канал webhook отключён.');
            }
            foreach ($events as $event) {
                if ($event->provider !== $inbox->provider()) {
                    throw new \DomainException('Нормализованное событие не соответствует провайдеру webhook.');
                }
                if ('BUTTON' === ($event->payload['kind'] ?? null) && (null === $connection || !$connection->isActive() || $connection->isPendingActivation() || !is_string($event->payload['userId'] ?? null) || !hash_equals($connection->address(), $event->payload['userId']))) {
                    // A provider-authenticated callback from another user is not actionable.
                    continue;
                }
                // Durable event handoff happens before inbox completion. A retry is safe
                // because the normalized-event sink is unique by tenant/provider/event.
                $eventId = $this->normalizedEvents->record($inbox->organizationId(), $inbox->channelConnectionId(), $event);
                if (null !== $eventId) {
                    $this->bus->dispatch(new ProcessNormalizedWebhookEvent($inbox->organizationId(), $eventId));
                }
            }
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
