<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

use App\Module\Communications\Domain\Model\OutboundMessage;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use Symfony\Component\Uid\Ulid;

final readonly class OutboundMessageRetryService
{
    public function __construct(private CommunicationStore $communications)
    {
    }

    public function retry(AdministratorAccount $administrator, Ulid $messageId, \DateTimeImmutable $at): OutboundMessage
    {
        $message = $this->communications->findOutbound($messageId);
        if (null === $message || !$message->organizationId()->equals($administrator->organizationId())) {
            throw new \OutOfBoundsException('Сообщение не найдено.');
        }
        $message->retryManually($at);
        $this->communications->save($message);

        return $message;
    }
}
