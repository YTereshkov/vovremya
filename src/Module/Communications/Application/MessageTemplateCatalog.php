<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

use App\Module\Communications\Domain\Model\MessageTemplateType;
use App\Module\Communications\Domain\Model\OrganizationMessageTemplate;
use App\Module\Identity\Domain\Model\AdministratorAccount;

final readonly class MessageTemplateCatalog
{
    private const DEFAULTS = [
        'CONFIRMATION' => 'Напоминаем: {date}, в {time} у вас {service}. Подтвердите, пожалуйста, сможете ли прийти.',
        'TRANSFER' => 'Для занятия {date} в {time} доступны новые варианты времени. Выберите подходящий вариант.',
        'FREE_WINDOW' => 'Появилось свободное окно: {date} в {time}, {service}. Подойдёт ли вам это время?',
        'PERMANENT_PLACE' => 'Освободилось постоянное место {service} {date}: {time}. Хотите закрепить это расписание?',
    ];

    public function __construct(private MessageTemplateStore $store)
    {
    }

    /** @return list<array{type: string, body: string, isDefault: bool}> */
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
                'isDefault' => null === $template,
            ];
        }, MessageTemplateType::cases());
    }

    /** @return array{type: string, body: string, isDefault: bool} */
    public function save(AdministratorAccount $actor, MessageTemplateType $type, string $body): array
    {
        $template = $this->store->find($type);
        if (null === $template) {
            $template = OrganizationMessageTemplate::create($actor->organization(), $type, $body);
        } else {
            $template->changeBody($body);
        }
        $this->store->save($template);

        return ['type' => $type->value, 'body' => $template->body(), 'isDefault' => false];
    }

    /** @return array{type: string, body: string, isDefault: bool} */
    public function restore(MessageTemplateType $type): array
    {
        $template = $this->store->find($type);
        if (null !== $template) {
            $this->store->remove($template);
        }

        return ['type' => $type->value, 'body' => self::DEFAULTS[$type->value], 'isDefault' => true];
    }

    public function body(MessageTemplateType $type): string
    {
        return $this->store->find($type)?->body() ?? self::DEFAULTS[$type->value];
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
}
