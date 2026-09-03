<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Handler;

use App\Shared\Infrastructure\Messenger\Message\InfrastructureHeartbeat;
use DateTimeImmutable;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class InfrastructureHeartbeatHandler
{
    public function __construct(
        #[Autowire(service: 'cache.infrastructure')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function __invoke(InfrastructureHeartbeat $message): void
    {
        $item = $this->cache->getItem(InfrastructureHeartbeat::CACHE_KEY);
        $item->set((new DateTimeImmutable())->format(DATE_ATOM));
        $item->expiresAfter(600);

        $this->cache->save($item);
    }
}
