<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\AvailabilityConflict;

final readonly class AvailabilityDecision
{
    private function __construct(public bool $available, public ?AvailabilityConflict $conflict)
    {
    }

    public static function available(): self
    {
        return new self(true, null);
    }

    public static function unavailable(AvailabilityConflict $conflict): self
    {
        return new self(false, $conflict);
    }

    /** @return array{available: bool, conflict: array{code: string, message: string}|null} */
    public function toArray(): array
    {
        return ['available' => $this->available, 'conflict' => $this->conflict?->toArray()];
    }
}
