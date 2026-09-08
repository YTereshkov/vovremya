<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

use App\Module\Communications\Domain\Model\NotificationIntent;
use App\Module\Communications\Domain\Model\OutboundMessage;
use App\Module\Communications\Domain\Model\WebhookInbox;
use App\Shared\Domain\MultiTenancy\OrganizationOwned;
use Symfony\Component\Uid\Ulid;

interface CommunicationStore
{
    /** @return list<OutboundMessage> */
    public function pendingOutbound(int $limit = 50): array;

    /** @return list<WebhookInbox> */
    public function pendingWebhooks(int $limit = 50): array;

    public function findOutbound(Ulid $id): ?OutboundMessage;

    public function findWebhook(string $provider, string $externalEventId): ?WebhookInbox;

    public function findWebhookById(Ulid $id): ?WebhookInbox;

    /** @return array{inbox: WebhookInbox, duplicate: bool} */
    public function recordWebhook(WebhookInbox $inbox): array;

    public function save(OrganizationOwned ...$entities): void;

    public function claimOutbound(Ulid $id): ?OutboundMessage;

    public function claimWebhook(Ulid $id): ?WebhookInbox;

    public function transactional(callable $operation): mixed;
}
