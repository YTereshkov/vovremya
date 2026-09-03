<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console;

use App\Shared\Infrastructure\Messenger\Message\InfrastructureHeartbeat;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:infrastructure:heartbeat',
    description: 'Dispatches or reads the infrastructure heartbeat.',
)]
final class InfrastructureHeartbeatCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        #[Autowire(service: 'cache.infrastructure')]
        private readonly CacheItemPoolInterface $cache,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('status', null, InputOption::VALUE_NONE, 'Read the last handled heartbeat.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('status')) {
            $item = $this->cache->getItem(InfrastructureHeartbeat::CACHE_KEY);

            if (!$item->isHit()) {
                $output->writeln('<comment>No handled heartbeat found.</comment>');

                return Command::FAILURE;
            }

            $output->writeln((string) $item->get());

            return Command::SUCCESS;
        }

        $this->messageBus->dispatch(new InfrastructureHeartbeat());
        $output->writeln('<info>Heartbeat queued.</info>');

        return Command::SUCCESS;
    }
}
