<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

use App\Module\Communications\Domain\Model\CommunicationProvider;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class ChannelProviderRegistry
{
    /** @param iterable<ChannelProvider> $providers */
    public function __construct(
        #[AutowireIterator('vovremya.communication_provider')]
        private iterable $providers,
    ) {
    }

    public function get(CommunicationProvider $provider): ChannelProvider
    {
        foreach ($this->providers as $candidate) {
            if ($candidate->provider() === $provider) {
                return $candidate;
            }
        }

        throw new \DomainException(sprintf('Канал %s пока не подключён.', $provider->value));
    }

    /** @return list<ChannelProvider> */
    public function all(): array
    {
        return iterator_to_array($this->providers, false);
    }
}
