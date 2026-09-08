<?php

declare(strict_types=1);

namespace App\Module\Communications\Application\Message;

use App\Module\Organization\Application\OrganizationAwareMessage;
use App\Shared\Infrastructure\Messenger\AsyncMessage;
use Symfony\Component\Uid\Ulid;

final readonly class SendOutboundMessage implements AsyncMessage, OrganizationAwareMessage
{
    public function __construct(private Ulid $organizationId, private Ulid $outboundMessageId)
    {
    }

    public function organizationId(): Ulid { return $this->organizationId; }
    public function outboundMessageId(): Ulid { return $this->outboundMessageId; }
}
