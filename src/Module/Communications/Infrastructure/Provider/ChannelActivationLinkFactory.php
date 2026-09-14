<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Provider;

use App\Module\Clients\Application\ChannelActivationLinkFactory as ChannelActivationLinkFactoryContract;
use App\Module\Communications\Application\ChannelProviderRegistry;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ChannelActivationLinkFactory implements ChannelActivationLinkFactoryContract
{
    public function __construct(
        private ChannelProviderRegistry $providers,
        #[Autowire('%env(MAX_BOT_USERNAME)%')]
        private string $maxBotUsername,
        #[Autowire('%env(TELEGRAM_BOT_USERNAME)%')]
        private string $telegramBotUsername,
        #[Autowire('%env(WHATSAPP_BUSINESS_PHONE)%')]
        private string $whatsAppBusinessPhone,
    ) {
    }

    public function create(string $provider, string $token): string
    {
        $provider = CommunicationProvider::from($provider);
        $capabilities = $this->providers->get($provider)->capabilities();
        if (!$capabilities->supportsDeepLink) {
            throw new \DomainException('Этот канал не поддерживает подключение по ссылке.');
        }
        return match ($provider) {
            CommunicationProvider::MAX => $this->botLink('https://max.ru', $this->maxBotUsername, $token, 'MAX'),
            CommunicationProvider::TELEGRAM => $this->botLink('https://t.me', $this->telegramBotUsername, $token, 'Telegram'),
            CommunicationProvider::WHATSAPP => $this->whatsAppLink($token),
        };
    }

    private function botLink(string $baseUrl, string $configuredUsername, string $token, string $label): string
    {
        $username = trim($configuredUsername, " \t\n\r\0\x0B@/");
        if ('' === $username || !preg_match('/^[A-Za-z0-9_.-]+$/', $username)) {
            throw new \DomainException(sprintf('Для %s не настроено имя бота.', $label));
        }

        return sprintf('%s/%s?start=%s', $baseUrl, rawurlencode($username), rawurlencode($token));
    }

    private function whatsAppLink(string $token): string
    {
        $phone = preg_replace('/\D+/', '', $this->whatsAppBusinessPhone) ?? '';
        if (!preg_match('/^[1-9]\d{4,14}$/D', $phone)) {
            throw new \DomainException('Для WhatsApp не настроен номер отправителя.');
        }

        return sprintf('https://wa.me/%s?text=%s', $phone, rawurlencode('VOVREMYA_CONNECT '.$token));
    }
}
