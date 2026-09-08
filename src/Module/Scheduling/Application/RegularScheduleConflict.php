<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

final readonly class RegularScheduleConflict
{
    public function __construct(
        public string $date,
        public string $startTime,
        public string $code,
        public string $message,
    ) {
    }

    /** @return array{date: string, startTime: string, code: string, message: string} */
    public function toArray(): array
    {
        return ['date' => $this->date, 'startTime' => $this->startTime, 'code' => $this->code, 'message' => $this->message];
    }
}
