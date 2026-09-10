<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\Model\ConfirmationActionType;
use Symfony\Component\Uid\Ulid;

final readonly class ConsumedConfirmationAction
{
    public function __construct(public Ulid $appointmentId, public ConfirmationActionType $type)
    {
    }
}
