<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Scheduler;

use App\Shared\Infrastructure\Messenger\Message\InfrastructureHeartbeat;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule('infrastructure')]
final class InfrastructureSchedule implements ScheduleProviderInterface
{
    private ?Schedule $schedule = null;

    public function __construct(
        #[Autowire(service: 'cache.scheduler')]
        private readonly CacheInterface $cache,
        #[Autowire('%env(string:INFRASTRUCTURE_HEARTBEAT_INTERVAL)%')]
        private readonly string $heartbeatInterval,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return $this->schedule ??= (new Schedule())
            ->add(RecurringMessage::every(
                $this->heartbeatInterval,
                new RedispatchMessage(new InfrastructureHeartbeat(), 'async'),
            ))
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
    }
}
