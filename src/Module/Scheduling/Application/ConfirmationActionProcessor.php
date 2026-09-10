<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\Model\ConfirmationActionType;
use Symfony\Component\Uid\Ulid;

final readonly class ConfirmationActionProcessor
{
    public function __construct(
        private AppointmentConfirmationStore $store,
        private AppointmentHistoryRecorder $history,
        private TransferService $transfers,
    ) {
    }

    public function consume(Ulid $actionId, string $token, Ulid $channelConnectionId, \DateTimeImmutable $now): void
    {
        $this->store->transactional(function () use ($actionId, $token, $channelConnectionId, $now): void {
            $consumed = $this->store->consumeAction($actionId, $token, $channelConnectionId, $now);
            if (null === $consumed) {
                return;
            }

            if (ConfirmationActionType::RequestTransfer === $consumed->type) {
                $this->transfers->requestFromClient($consumed->appointmentId, $channelConnectionId, $now);

                return;
            }

            $this->history->recordByAppointmentId(
                $consumed->appointmentId,
                ConfirmationActionType::Confirm === $consumed->type ? 'CONFIRMATION_CONFIRMED' : 'CONFIRMATION_CANNOT_ATTEND',
                [],
                null,
                $now,
            );
        });
    }
}
