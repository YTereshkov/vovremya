<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

use App\Module\Clients\Application\ChannelConnectionResolver;
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
        private ChannelConnectionResolver $connections,
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
        if (null === $recipientChannelId) {
            throw new \DomainException('Для внешнего уведомления требуется подключённый канал.');
        }
        $connection = $this->connections->findChannelForTenant($recipientChannelId);
        if (null === $connection || !$connection->isActive()) {
            throw new \DomainException('Канал получателя не найден или отключён.');
        }
        if ($connection->provider() !== $provider->value) {
            throw new \DomainException('Канал получателя не соответствует провайдеру уведомления.');
        }
        if (trim($recipientAddress) !== $connection->address()) {
            throw new \DomainException('Адрес получателя должен быть взят из подключённого канала.');
        }
        OutboundMessage::assertMetadata($metadata);
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
