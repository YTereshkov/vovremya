<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain;

final readonly class AvailabilityConflict
{
    private function __construct(public string $code, public string $message)
    {
    }

    public static function specialistNotWorking(): self
    {
        return new self('SPECIALIST_NOT_WORKING', 'Специалист не работает в выбранное время.');
    }

    public static function timeAlreadyUnavailable(): self
    {
        return new self('TIME_ALREADY_UNAVAILABLE', 'Время уже недоступно.');
    }

    /** @return array{code: string, message: string} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'message' => $this->message];
    }
}
