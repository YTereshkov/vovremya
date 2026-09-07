<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain;

final class TimeUnavailable extends \DomainException
{
    public function __construct(public readonly AvailabilityConflict $conflict, ?\Throwable $previous = null)
    {
        parent::__construct($conflict->message, 0, $previous);
    }
}
