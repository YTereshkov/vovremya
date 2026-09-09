<?php

declare(strict_types=1);

namespace App\Module\Communications\Application\Message;

use App\Module\Communications\Application\NormalizedWebhookEventStore;
use App\Module\Communications\Application\CommunicationStore;
use App\Module\Organization\Application\OrganizationContext;
use App\Module\Organization\Application\OrganizationDirectory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class PublishPendingOutboundMessagesHandler
{
    public function __construct(
        private OrganizationDirectory $organizations,
        private OrganizationContext $organizationContext,
        private CommunicationStore $store,
        private NormalizedWebhookEventStore $normalizedEvents,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(PublishPendingOutboundMessages $message): void
    {
        foreach ($this->organizations->allIds() as $organizationId) {
            $this->organizationContext->runWith($organizationId, function () use ($organizationId): void {
                foreach ($this->store->pendingOutbound() as $outbound) {
                    $outbound->markPublished();
                    $this->store->save($outbound);
                    $this->bus->dispatch(new SendOutboundMessage($organizationId, $outbound->id()));
                }
                foreach ($this->store->pendingWebhooks() as $inbox) {
                    $this->bus->dispatch(new ProcessWebhookInbox($organizationId, $inbox->id()));
                }
                foreach ($this->normalizedEvents->pending() as $event) {
                    $this->bus->dispatch(new ProcessNormalizedWebhookEvent($organizationId, $event->id()));
                }
            });
        }
    }
}
