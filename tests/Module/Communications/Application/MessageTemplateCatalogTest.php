<?php

declare(strict_types=1);

namespace App\Tests\Module\Communications\Application;

use App\Module\Communications\Application\MessageTemplateCatalog;
use App\Module\Communications\Application\MessageTemplateStore;
use App\Module\Communications\Domain\Model\MessageTemplateType;
use App\Module\Communications\Domain\Model\OrganizationMessageTemplate;
use PHPUnit\Framework\TestCase;

final class MessageTemplateCatalogTest extends TestCase
{
    public function testServiceOverrideIsUsedOnlyForConfirmationTemplate(): void
    {
        $catalog = new MessageTemplateCatalog(new class implements MessageTemplateStore {
            public function all(): array { return []; }
            public function find(MessageTemplateType $type): ?OrganizationMessageTemplate { return null; }
            public function save(OrganizationMessageTemplate $template): void {}
            public function remove(OrganizationMessageTemplate $template): void {}
        });
        $variables = [
            'date' => '12 сентября',
            'time' => '15:30',
            'service' => 'Диагностика',
            'client_name' => 'Анна',
            'contact_name' => 'Мария',
        ];

        self::assertSame(
            'Диагностика для Анна: 12 сентября в 15:30.',
            $catalog->render(
                MessageTemplateType::CONFIRMATION,
                $variables,
                '{service} для {client_name}: {date} в {time}.',
            ),
        );
        self::assertSame(
            'Для занятия 12 сентября в 15:30 доступны новые варианты времени. Выберите подходящий вариант.',
            $catalog->render(MessageTemplateType::TRANSFER, $variables, 'Этот текст не должен использоваться.'),
        );
    }
}
