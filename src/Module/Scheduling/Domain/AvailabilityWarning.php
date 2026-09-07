<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain;

final readonly class AvailabilityWarning
{
    /** @param array<string, int|string> $details */
    private function __construct(
        public string $code,
        public string $title,
        public string $message,
        public array $details,
    ) {
    }

    public static function lunch(string $start, string $end): self
    {
        return new self(
            'LUNCH_OVERLAP',
            'Занятие пересекается с обедом',
            sprintf('%s–%s отмечено как обед. Обед останется в расписании, исключение будет создано только для этого занятия.', $start, $end),
            ['lunchStart' => $start, 'lunchEnd' => $end],
        );
    }

    public static function shortBreak(int $minutes, string $position, string $adjacentAt): self
    {
        return new self(
            'SHORT_BREAK',
            sprintf('Между занятиями останется %d минут', $minutes),
            'Рекомендуемый перерыв — не менее 15 минут.',
            ['minutes' => $minutes, 'position' => $position, 'adjacentAt' => $adjacentAt],
        );
    }

    /** @return array{code: string, title: string, message: string, details: array<string, int|string>} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'title' => $this->title, 'message' => $this->message, 'details' => $this->details];
    }
}
