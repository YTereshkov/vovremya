<?php

declare(strict_types=1);

namespace App\Tests\Module\Organization\Infrastructure\Messenger;

use App\Module\Identity\Application\AdministratorAccountReader;
use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Application\Exception\CrossOrganizationContext;
use App\Module\Organization\Application\Exception\MissingOrganizationContext;
use App\Module\Organization\Application\Exception\UnknownOrganization;
use App\Module\Organization\Application\OrganizationAwareMessage;
use App\Module\Organization\Application\OrganizationContext;
use App\Module\Organization\Infrastructure\Messenger\OrganizationContextMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Uid\Ulid;

final class OrganizationContextMiddlewareTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private OrganizationContext $organizationContext;
    private AdministratorAccountReader $administratorAccountReader;
    private OrganizationContextMiddleware $middleware;
    private AdministratorAccount $firstAdministrator;
    private AdministratorAccount $secondAdministrator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $handler = self::getContainer()->get(CreateAdministratorHandler::class);
        $this->firstAdministrator = $handler
            ->create('Первый кабинет', 'worker-first@example.test', 'test-password-secret', 'Europe/Moscow');
        $this->secondAdministrator = $handler
            ->create('Второй кабинет', 'worker-second@example.test', 'test-password-secret', 'Europe/Kaliningrad');
        $this->organizationContext = self::getContainer()->get(OrganizationContext::class);
        $this->administratorAccountReader = self::getContainer()->get(AdministratorAccountReader::class);
        $this->middleware = self::getContainer()->get(OrganizationContextMiddleware::class);
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }

        $this->entityManager->clear();
        parent::tearDown();
    }

    public function testKnownOrganizationScopesHandlerAndClearsContext(): void
    {
        $recordingMiddleware = new RecordingOrganizationMiddleware(
            $this->organizationContext,
            $this->administratorAccountReader,
            $this->secondAdministrator->id(),
            $this->firstAdministrator->id(),
        );

        $this->middleware->handle(
            new Envelope(new TestOrganizationMessage($this->secondAdministrator->organizationId())),
            new StackMiddleware($recordingMiddleware),
        );

        self::assertSame(
            $this->secondAdministrator->organizationId()->toRfc4122(),
            $recordingMiddleware->organizationId,
        );
        self::assertSame($this->secondAdministrator->id()->toRfc4122(), $recordingMiddleware->foundAdministratorId);
        self::assertFalse($recordingMiddleware->foreignAdministratorWasVisible);
        self::assertNull($this->organizationContext->currentIdOrNull());
    }

    public function testScopedRepositoryFailsClosedWithoutContext(): void
    {
        $this->expectException(MissingOrganizationContext::class);
        $this->administratorAccountReader->findInCurrentOrganization($this->firstAdministrator->id());
    }

    public function testUnknownOrganizationIsRejectedAndContextIsCleared(): void
    {
        try {
            $this->middleware->handle(
                new Envelope(new TestOrganizationMessage(new Ulid())),
                new StackMiddleware(new TerminalMiddleware()),
            );
            self::fail('Unknown organization was accepted.');
        } catch (UnknownOrganization) {
            self::addToAssertionCount(1);
        }

        self::assertNull($this->organizationContext->currentIdOrNull());
    }

    public function testActiveScopeCannotSwitchOrganization(): void
    {
        try {
            $this->organizationContext->runWith(
                $this->firstAdministrator->organizationId(),
                fn (): Envelope => $this->middleware->handle(
                    new Envelope(new TestOrganizationMessage($this->secondAdministrator->organizationId())),
                    new StackMiddleware(new TerminalMiddleware()),
                ),
            );
            self::fail('Organization context switched tenants.');
        } catch (CrossOrganizationContext) {
            self::addToAssertionCount(1);
        }

        self::assertNull($this->organizationContext->currentIdOrNull());
    }
}

final readonly class TestOrganizationMessage implements OrganizationAwareMessage
{
    public function __construct(private Ulid $organizationId)
    {
    }

    public function organizationId(): Ulid
    {
        return $this->organizationId;
    }
}

final class RecordingOrganizationMiddleware implements MiddlewareInterface
{
    public ?string $organizationId = null;
    public ?string $foundAdministratorId = null;
    public bool $foreignAdministratorWasVisible = false;

    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly AdministratorAccountReader $administratorAccountReader,
        private readonly Ulid $administratorId,
        private readonly Ulid $foreignAdministratorId,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $this->organizationId = $this->organizationContext->currentId()->toRfc4122();
        $this->foundAdministratorId = $this->administratorAccountReader
            ->findInCurrentOrganization($this->administratorId)
            ?->id()
            ->toRfc4122();
        $this->foreignAdministratorWasVisible = null !== $this->administratorAccountReader
            ->findInCurrentOrganization($this->foreignAdministratorId);

        return $envelope;
    }
}

final class TerminalMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        return $envelope;
    }
}
