<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Communications\Domain\Model\WebhookInbox;
use App\Module\Organization\Application\OrganizationContext;
use App\Module\Organization\Domain\Model\Organization;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class WebhookInboxRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CommunicationStore $store,
        private OrganizationContext $organizationContext,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function receive(
        CommunicationProvider $provider,
        string $externalEventId,
        array $payload,
    ): array {
        $organizationId = $this->organizationContext->currentId();
        $organization = $this->entityManager->getReference(Organization::class, $organizationId);
        $inbox = WebhookInbox::receive($organization, $provider, $externalEventId, $payload);

        return $this->store->recordWebhook($inbox);
    }
}
