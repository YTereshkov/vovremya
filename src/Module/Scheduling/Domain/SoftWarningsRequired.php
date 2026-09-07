<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain;

final class SoftWarningsRequired extends \DomainException
{
    /** @param list<AvailabilityWarning> $warnings */
    public function __construct(public readonly array $warnings)
    {
        parent::__construct('Подтвердите предупреждения перед созданием занятия.');
    }
}
