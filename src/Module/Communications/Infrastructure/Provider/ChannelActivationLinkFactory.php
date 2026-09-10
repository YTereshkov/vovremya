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
    ) {
    }

    public function create(string $provider, string $token): string
    {
        $provider = CommunicationProvider::from($provider);
        $capabilities = $this->providers->get($provider)->capabilities();
        if (!$capabilities->supportsDeepLink) {
            throw new \DomainException('Этот канал не поддерживает подключение по ссылке.');
        }
        if (CommunicationProvider::MAX !== $provider) {
            throw new \DomainException(sprintf('Подключение %s пока не настроено.', $provider->value));
        }
        $username = trim($this->maxBotUsername, " \t\n\r\0\x0B@/");
        if ('' === $username || !preg_match('/^[A-Za-z0-9_.-]+$/', $username)) {
            throw new \DomainException('Для MAX не настроено имя бота.');
        }

        return sprintf('https://max.ru/%s?start=%s', rawurlencode($username), rawurlencode($token));
    }
}
