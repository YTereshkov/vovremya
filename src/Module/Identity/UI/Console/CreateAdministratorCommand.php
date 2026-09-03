<?php

declare(strict_types=1);

namespace App\Module\Identity\UI\Console;

use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Exception\DuplicateAdministratorEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:admin:create',
    description: 'Creates an organization and its first administrator account.',
)]
final class CreateAdministratorCommand extends Command
{
    public function __construct(private readonly CreateAdministratorHandler $handler)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('organization-name', InputArgument::OPTIONAL, 'Organization name')
            ->addArgument('email', InputArgument::OPTIONAL, 'Administrator email')
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Organization timezone', 'Europe/Moscow');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $organizationName = $this->requiredValue(
            $input->getArgument('organization-name'),
            'Organization name: ',
            $input,
            $output,
        );
        $email = $this->requiredValue(
            $input->getArgument('email'),
            'Administrator email: ',
            $input,
            $output,
        );
        $password = $this->hiddenValue('Administrator password: ', $input, $output);
        $passwordConfirmation = $this->hiddenValue('Repeat administrator password: ', $input, $output);

        if (!hash_equals($password, $passwordConfirmation)) {
            $io->error('Passwords do not match.');

            return Command::FAILURE;
        }

        try {
            $administrator = $this->handler->create(
                $organizationName,
                $email,
                $password,
                (string) $input->getOption('timezone'),
            );
        } catch (DuplicateAdministratorEmail|\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Administrator %s created for organization %s.',
            $administrator->email(),
            $administrator->organization()->name(),
        ));

        return Command::SUCCESS;
    }

    private function requiredValue(
        mixed $value,
        string $questionText,
        InputInterface $input,
        OutputInterface $output,
    ): string {
        if (is_string($value) && '' !== trim($value)) {
            return trim($value);
        }

        $question = new Question($questionText);
        $question->setValidator(static function (mixed $answer): string {
            if (!is_string($answer) || '' === trim($answer)) {
                throw new \RuntimeException('A value is required.');
            }

            return trim($answer);
        });

        return (string) $this->getHelper('question')->ask($input, $output, $question);
    }

    private function hiddenValue(string $questionText, InputInterface $input, OutputInterface $output): string
    {
        $question = new Question($questionText);
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $question->setValidator(static function (mixed $answer): string {
            if (!is_string($answer) || '' === $answer) {
                throw new \RuntimeException('Password cannot be empty.');
            }

            return $answer;
        });

        return (string) $this->getHelper('question')->ask($input, $output, $question);
    }
}
