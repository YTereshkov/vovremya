<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Infrastructure\Scheduler;

use App\Module\Scheduling\Application\Message\MaterializeRegularSchedules;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule('regular_scheduling')]
final class RegularSchedulingSchedule implements ScheduleProviderInterface
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
            ->add(RecurringMessage::every('1 day', new RedispatchMessage(new MaterializeRegularSchedules(), 'async')))
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
    }
}
