<?php

declare(strict_types=1);

namespace App\Tests\Module\Communications\Infrastructure\Provider;

use App\Module\Communications\Application\ChannelProviderRegistry;
use App\Module\Communications\Infrastructure\Provider\ChannelActivationLinkFactory;
use App\Module\Communications\Infrastructure\Provider\Max\MaxApiClient;
use App\Module\Communications\Infrastructure\Provider\Max\MaxChannelProvider;
use App\Module\Communications\Infrastructure\Provider\Telegram\TelegramApiClient;
use App\Module\Communications\Infrastructure\Provider\Telegram\TelegramChannelProvider;
use App\Module\Communications\Infrastructure\Provider\WhatsApp\WhatsAppApiClient;
use App\Module\Communications\Infrastructure\Provider\WhatsApp\WhatsAppChannelProvider;
use PHPUnit\Framework\TestCase;

final class ChannelActivationLinkFactoryTest extends TestCase
{
    public function testCreatesProviderSpecificActivationLinks(): void
    {
        $factory = new ChannelActivationLinkFactory(
            new ChannelProviderRegistry([
                new MaxChannelProvider($this->createStub(MaxApiClient::class)),
                new TelegramChannelProvider($this->createStub(TelegramApiClient::class)),
                new WhatsAppChannelProvider($this->createStub(WhatsAppApiClient::class)),
            ]),
            'MaxBot',
            '@TelegramBot',
            '+7 (999) 000-00-00',
        );

        self::assertSame('https://max.ru/MaxBot?start=token_123', $factory->create('MAX', 'token_123'));
        self::assertSame('https://t.me/TelegramBot?start=token_123', $factory->create('TELEGRAM', 'token_123'));
        self::assertSame('https://wa.me/79990000000?text=VOVREMYA_CONNECT%20token_123', $factory->create('WHATSAPP', 'token_123'));
    }

    public function testRejectsZeroPrefixedWhatsAppNumber(): void
    {
        $factory = new ChannelActivationLinkFactory(
            new ChannelProviderRegistry([
                new WhatsAppChannelProvider($this->createStub(WhatsAppApiClient::class)),
            ]),
            'MaxBot',
            'TelegramBot',
            '00 7 999 000-00-00',
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Для WhatsApp не настроен номер отправителя.');

        $factory->create('WHATSAPP', 'token_123');
    }
}
