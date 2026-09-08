<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Scheduler;

use App\Module\Communications\Application\Message\PublishPendingOutboundMessages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule('communications')]
final class CommunicationsSchedule implements ScheduleProviderInterface
{
    private ?Schedule $schedule = null;

    public function __construct(
        #[Autowire(service: 'cache.scheduler')]
        private readonly CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return $this->schedule ??= (new Schedule())
            ->add(RecurringMessage::every('1 minute', new RedispatchMessage(new PublishPendingOutboundMessages(), 'async')))
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
    }
}
