<?php

declare(strict_types=1);

namespace App\Module\Communications\Application\Message;

use App\Module\Organization\Application\OrganizationAwareMessage;
use App\Shared\Infrastructure\Messenger\AsyncMessage;
use Symfony\Component\Uid\Ulid;

final readonly class ProcessWebhookInbox implements AsyncMessage, OrganizationAwareMessage
{
    public function __construct(private Ulid $organizationId, private Ulid $inboxId)
    {
    }

    public function organizationId(): Ulid { return $this->organizationId; }
    public function inboxId(): Ulid { return $this->inboxId; }
}
