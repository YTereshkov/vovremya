<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\UI\Http;

use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Application\Exception\CrossOrganizationContext;
use App\Module\Organization\Application\OrganizationContext;
use App\Module\Organization\Application\OrganizationPermission;
use App\Shared\Domain\MultiTenancy\CrossOrganizationAssociation;
use App\Shared\Domain\MultiTenancy\OrganizationIsolation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class TenantIsolationControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private AbstractBrowser $client;
    private AdministratorAccount $firstAdministrator;
    private AdministratorAccount $secondAdministrator;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $handler = self::getContainer()->get(CreateAdministratorHandler::class);
        $this->firstAdministrator = $handler->create(
            'Первый кабинет',
            'first-admin@example.test',
            'test-password-secret',
            'Europe/Moscow',
        );
        $this->secondAdministrator = $handler->create(
            'Второй кабинет',
            'second-admin@example.test',
            'test-password-secret',
            'Europe/Kaliningrad',
        );
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }

        $this->entityManager->clear();
        parent::tearDown();
    }

    public function testDirectIdentifierLookupIsScopedToAuthenticatedOrganization(): void
    {
        $this->client->request('GET', '/api/administrators/'.$this->firstAdministrator->id()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->loginAsFirstAdministrator();

        $this->client->request('GET', '/api/administrators/'.$this->firstAdministrator->id()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSame('first-admin@example.test', $this->jsonResponse()['email']);

        $this->client->request('GET', '/api/administrators/'.$this->secondAdministrator->id()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('Administrator not found.', $this->jsonResponse()['message']);

        $this->client->request('GET', '/api/administrators/not-a-valid-id');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testVoterAndContextRejectForeignOrganization(): void
    {
        $this->loginAsFirstAdministrator();

        $authorizationChecker = self::getContainer()->get(AuthorizationCheckerInterface::class);
        self::assertTrue($authorizationChecker->isGranted(OrganizationPermission::VIEW, $this->firstAdministrator));
        self::assertTrue($authorizationChecker->isGranted(OrganizationPermission::EDIT, $this->firstAdministrator));
        self::assertFalse($authorizationChecker->isGranted(OrganizationPermission::VIEW, $this->secondAdministrator));
        self::assertFalse($authorizationChecker->isGranted(OrganizationPermission::EDIT, $this->secondAdministrator));

        $organizationContext = self::getContainer()->get(OrganizationContext::class);
        self::assertTrue($organizationContext->currentId()->equals($this->firstAdministrator->organizationId()));

        $this->expectException(CrossOrganizationContext::class);
        $organizationContext->runWith($this->secondAdministrator->organizationId(), static fn (): null => null);
    }

    public function testCrossOrganizationAssociationIsRejected(): void
    {
        OrganizationIsolation::assertCanAssociate($this->firstAdministrator, $this->firstAdministrator);
        self::addToAssertionCount(1);

        $this->expectException(CrossOrganizationAssociation::class);
        OrganizationIsolation::assertCanAssociate($this->firstAdministrator, $this->secondAdministrator);
    }

    private function loginAsFirstAdministrator(): void
    {
        $this->client->request('GET', '/api/auth/csrf');
        $csrfToken = $this->jsonResponse()['token'];
        $this->client->request('POST', '/api/login', [
            'email' => $this->firstAdministrator->email(),
            'password' => 'test-password-secret',
            '_csrf_token' => $csrfToken,
        ]);

        self::assertResponseIsSuccessful();
    }

    /** @return array<string, mixed> */
    private function jsonResponse(): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }
}
