<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Communications\Domain\Model\NotificationIntent;
use App\Module\Communications\Domain\Model\OutboundMessage;
use App\Module\Organization\Application\OrganizationContext;
use App\Module\Organization\Domain\Model\Organization;
use Symfony\Component\Uid\Ulid;
use Doctrine\ORM\EntityManagerInterface;

final readonly class NotificationOutbox
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CommunicationStore $store,
        private OrganizationContext $organizationContext,
    ) {
    }

    /** @param array<string, mixed> $payload
     *  @param array<string, mixed> $buttons
     *  @param array<string, mixed> $metadata
     */
    public function queue(
        string $type,
        ?Ulid $recipientChannelId,
        CommunicationProvider $provider,
        string $recipientAddress,
        string $body,
        array $payload = [],
        array $buttons = [],
        array $metadata = [],
        ?string $dedupeKey = null,
    ): OutboundMessage {
        $organization = $this->entityManager->getReference(Organization::class, $this->organizationContext->currentId());

        return $this->store->transactional(function () use ($organization, $type, $recipientChannelId, $provider, $recipientAddress, $body, $payload, $buttons, $metadata, $dedupeKey): OutboundMessage {
            $intent = NotificationIntent::create($organization, $type, $recipientChannelId, $payload, $dedupeKey);
            $outbound = OutboundMessage::create($organization, $intent->id(), $recipientChannelId, $provider, $recipientAddress, $body, $buttons, $metadata);
            // Flush the intent first so the scalar tenant FK of the outbox row is resolvable.
            $this->store->save($intent);
            $this->store->save($outbound);

            return $outbound;
        });
    }
}
