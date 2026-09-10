<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

use App\Module\Communications\Domain\Model\ConfirmationSettings;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Domain\Model\Organization;

final readonly class ConfirmationSettingsService
{
    public function __construct(private ConfirmationSettingsStore $store)
    {
    }

    public function forOrganization(Organization $organization): ConfirmationSettings
    {
        return $this->store->current() ?? ConfirmationSettings::defaults($organization);
    }

    public function change(AdministratorAccount $actor, array $input): ConfirmationSettings
    {
        $settings = $this->forOrganization($actor->organization());
        $settings->change(
            $this->string($input, 'requestTime'),
            $this->string($input, 'noResponseTime'),
            $this->boolean($input, 'reminderEnabled'),
            $this->integer($input, 'reminderLeadMinutes'),
            $this->string($input, 'reminderNotBefore'),
            $this->string($input, 'quietHoursStart'),
            $this->string($input, 'quietHoursEnd'),
        );
        $this->store->save($settings);

        return $settings;
    }

    /** @return array<string, mixed> */
    public function present(ConfirmationSettings $settings): array
    {
        return [
            'requestTime' => $settings->requestTime(),
            'noResponseTime' => $settings->noResponseTime(),
            'reminderEnabled' => $settings->reminderEnabled(),
            'reminderLeadMinutes' => $settings->reminderLeadMinutes(),
            'reminderNotBefore' => $settings->reminderNotBefore(),
            'quietHoursStart' => $settings->quietHoursStart(),
            'quietHoursEnd' => $settings->quietHoursEnd(),
        ];
    }

    private function string(array $input, string $key): string
    {
        if (!is_string($input[$key] ?? null)) {
            throw new \InvalidArgumentException('Заполните настройки подтверждений.');
        }

        return $input[$key];
    }

    private function boolean(array $input, string $key): bool
    {
        if (!is_bool($input[$key] ?? null)) {
            throw new \InvalidArgumentException('Некорректная настройка напоминания.');
        }

        return $input[$key];
    }

    private function integer(array $input, string $key): int
    {
        if (!is_int($input[$key] ?? null)) {
            throw new \InvalidArgumentException('Интервал напоминания должен быть целым числом минут.');
        }

        return $input[$key];
    }
}
