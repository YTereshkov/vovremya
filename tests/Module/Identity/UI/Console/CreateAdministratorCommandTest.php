<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\UI\Console;

use App\Kernel;
use App\Module\Identity\Application\AdministratorPasswordHasher;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Domain\Model\Organization;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateAdministratorCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();

        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        $this->entityManager->clear();
        parent::tearDown();
    }

    #[Test]
    public function itCreatesOrganizationAndAdministratorUsingTwoHiddenPasswordPrompts(): void
    {
        $command = $this->command();
        self::assertFalse($command->getDefinition()->hasArgument('password'));
        self::assertFalse($command->getDefinition()->hasOption('password'));

        $tester = new CommandTester($command);
        $tester->setInputs([
            'Кабинет Вовремя',
            'Admin@Example.test',
            'test-password-secret',
            'test-password-secret',
        ]);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringNotContainsString('test-password-secret', $tester->getDisplay());
        self::assertStringContainsString('Administrator password:', $tester->getDisplay());
        self::assertStringContainsString('Repeat administrator password:', $tester->getDisplay());

        $organizations = $this->entityManager->getRepository(Organization::class)->findAll();
        $administrators = $this->entityManager->getRepository(AdministratorAccount::class)->findAll();

        self::assertCount(1, $organizations);
        self::assertCount(1, $administrators);

        $administrator = $administrators[0];
        self::assertSame('admin@example.test', $administrator->email());
        self::assertSame($organizations[0]->id()->toRfc4122(), $administrator->organization()->id()->toRfc4122());
        self::assertTrue($administrator->isEnabled());
        self::assertNotSame('test-password-secret', $administrator->passwordHash());
        self::assertTrue(
            self::getContainer()
                ->get(AdministratorPasswordHasher::class)
                ->verify($administrator->passwordHash(), 'test-password-secret'),
        );
    }

    #[Test]
    public function itRejectsDuplicateNormalizedEmailWithoutCreatingAnotherOrganization(): void
    {
        $this->runCommand('Первый кабинет', 'admin@example.test');

        $tester = $this->runCommand('Второй кабинет', ' ADMIN@EXAMPLE.TEST ');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('already exists', $tester->getDisplay());
        self::assertSame(1, $this->entityManager->getRepository(Organization::class)->count([]));
        self::assertSame(1, $this->entityManager->getRepository(AdministratorAccount::class)->count([]));
    }

    #[Test]
    public function itRejectsDifferentPasswordConfirmation(): void
    {
        $tester = new CommandTester($this->command());
        $tester->setInputs([
            'Кабинет Вовремя',
            'admin@example.test',
            'first-password',
            'different-password',
        ]);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Passwords do not match.', $tester->getDisplay());
        self::assertSame(0, $this->entityManager->getRepository(Organization::class)->count([]));
        self::assertSame(0, $this->entityManager->getRepository(AdministratorAccount::class)->count([]));
    }

    private function command(): Command
    {
        $application = new Application(self::$kernel);

        return $application->find('app:admin:create');
    }

    private function runCommand(string $organizationName, string $email): CommandTester
    {
        $tester = new CommandTester($this->command());
        $tester->setInputs([
            $organizationName,
            $email,
            'test-password-secret',
            'test-password-secret',
        ]);
        $tester->execute([]);

        return $tester;
    }
}
