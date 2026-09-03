<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

#[When(env: 'dev')]
#[AsCommand(
    name: 'app:infrastructure:queue-failure-probe',
    description: 'Dispatches a development-only message that must reach the failure transport.',
)]
final class QueueFailureProbeCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->messageBus->dispatch(
            new RunCommandMessage('app:infrastructure:intentional-missing-command'),
            [new TransportNamesStamp(['async'])],
        );

        $output->writeln('<info>Failure probe queued.</info>');

        return Command::SUCCESS;
    }
}
