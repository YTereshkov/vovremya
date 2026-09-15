<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

use App\Module\Communications\Domain\Model\MessageTemplateType;
use App\Module\Communications\Domain\Model\OrganizationMessageTemplate;
use App\Module\Identity\Domain\Model\AdministratorAccount;

final readonly class MessageTemplateCatalog
{
    private const DEFAULTS = [
        'CONFIRMATION' => 'Здравствуйте, {contact_name}! Напоминаем: {date} в {time} у {client_name} занятие «{service}». Подтвердите, пожалуйста, сможете ли прийти.',
        'TRANSFER' => '{client_name}, для занятия {date} в {time} доступны новые варианты времени. Выберите подходящий вариант.',
        'FREE_WINDOW' => 'Здравствуйте, {contact_name}! Появилось свободное окно: {date} в {time}, услуга «{service}». Подойдёт ли вам это время?',
        'PERMANENT_PLACE' => 'Для {client_name} освободилось постоянное место на услугу «{service}»: {date} в {time}. Хотите закрепить это расписание?',
    ];

    private const CONFIRMATION_BUTTONS = [
        'confirm' => 'Будем',
        'cannotAttend' => 'Не сможем',
        'transfer' => 'Хотим перенести',
    ];

    public function __construct(private MessageTemplateStore $store)
    {
    }

    /** @return list<array{type: string, body: string, buttons: array<string, string>|null, isDefault: bool}> */
    public function list(): array
    {
        $overrides = [];
        foreach ($this->store->all() as $template) {
            $overrides[$template->type()->value] = $template;
        }

        return array_map(static function (MessageTemplateType $type) use ($overrides): array {
            $template = $overrides[$type->value] ?? null;

            return [
                'type' => $type->value,
                'body' => $template?->body() ?? self::DEFAULTS[$type->value],
                'buttons' => MessageTemplateType::CONFIRMATION === $type ? array_replace(self::CONFIRMATION_BUTTONS, $template?->buttonLabels() ?? []) : null,
                'isDefault' => null === $template,
            ];
        }, MessageTemplateType::cases());
    }

    /** @param array<string, mixed>|null $buttonLabels
     *  @return array{type: string, body: string, buttons: array<string, string>|null, isDefault: bool}
     */
    public function save(AdministratorAccount $actor, MessageTemplateType $type, string $body, ?array $buttonLabels = null): array
    {
        $template = $this->store->find($type);
        if (null === $template) {
            $template = OrganizationMessageTemplate::create($actor->organization(), $type, $body, MessageTemplateType::CONFIRMATION === $type ? $buttonLabels ?? self::CONFIRMATION_BUTTONS : []);
        } else {
            $template->changeBody($body);
            if (null !== $buttonLabels) {
                $template->changeButtonLabels($buttonLabels);
            }
        }
        $this->store->save($template);

        return $this->present($type, $template, false);
    }

    /** @return array{type: string, body: string, buttons: array<string, string>|null, isDefault: bool} */
    public function restore(MessageTemplateType $type): array
    {
        $template = $this->store->find($type);
        if (null !== $template) {
            $this->store->remove($template);
        }

        return $this->present($type, null, true);
    }

    public function body(MessageTemplateType $type): string
    {
        return $this->store->find($type)?->body() ?? self::DEFAULTS[$type->value];
    }

    /** @return array{confirm: string, cannotAttend: string, transfer: string} */
    public function confirmationButtonLabels(): array
    {
        return array_replace(self::CONFIRMATION_BUTTONS, $this->store->find(MessageTemplateType::CONFIRMATION)?->buttonLabels() ?? []);
    }

    /** @param array<string, string> $variables */
    public function render(MessageTemplateType $type, array $variables, ?string $serviceOverride = null): string
    {
        $body = null !== $serviceOverride && MessageTemplateType::CONFIRMATION === $type
            ? OrganizationMessageTemplate::validateBody($serviceOverride)
            : $this->body($type);
        $replace = [];
        foreach ($variables as $name => $value) {
            $replace['{'.$name.'}'] = $value;
        }

        return strtr($body, $replace);
    }

    /** @return array{type: string, body: string, buttons: array<string, string>|null, isDefault: bool} */
    private function present(MessageTemplateType $type, ?OrganizationMessageTemplate $template, bool $isDefault): array
    {
        return [
            'type' => $type->value,
            'body' => $template?->body() ?? self::DEFAULTS[$type->value],
            'buttons' => MessageTemplateType::CONFIRMATION === $type ? array_replace(self::CONFIRMATION_BUTTONS, $template?->buttonLabels() ?? []) : null,
            'isDefault' => $isDefault,
        ];
    }
}
