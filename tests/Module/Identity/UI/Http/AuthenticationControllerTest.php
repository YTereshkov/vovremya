<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\UI\Http;

use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticationControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private AbstractBrowser $client;
    private AdministratorAccount $administrator;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->administrator = self::getContainer()
            ->get(CreateAdministratorHandler::class)
            ->create('Кабинет Вовремя', 'admin@example.test', 'test-password-secret', 'Europe/Moscow');
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }

        $this->entityManager->clear();
        parent::tearDown();
    }

    public function testUnauthenticatedApiRequestIsRejected(): void
    {
        $this->client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testLoginRequiresCsrfAndCreatesSession(): void
    {
        $tokens = $this->csrfTokens();

        $this->client->request('POST', '/api/login', [
            'email' => $this->administrator->email(),
            'password' => 'test-password-secret',
            '_csrf_token' => $tokens['token'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonResponse()['authenticated']);

        $this->client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        $currentUser = $this->jsonResponse();
        self::assertSame('admin@example.test', $currentUser['email']);
        self::assertSame('Кабинет Вовремя', $currentUser['organization']['name']);
        self::assertSame('Europe/Moscow', $currentUser['organization']['timezone']);
    }

    public function testLoginRejectsInvalidCsrfToken(): void
    {
        $this->client->request('POST', '/api/login', [
            'email' => $this->administrator->email(),
            'password' => 'test-password-secret',
            '_csrf_token' => 'invalid-token',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testInvalidCredentialsAndDisabledAccountAreRejected(): void
    {
        $tokens = $this->csrfTokens();

        $this->client->request('POST', '/api/login', [
            'email' => $this->administrator->email(),
            'password' => 'wrong-password',
            '_csrf_token' => $tokens['token'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $administrator = $this->entityManager->find(AdministratorAccount::class, $this->administrator->id());
        self::assertInstanceOf(AdministratorAccount::class, $administrator);
        $administrator->disable();
        $this->entityManager->flush();
        $this->client->request('GET', '/api/auth/csrf');
        $tokens = $this->jsonResponse();

        $this->client->request('POST', '/api/login', [
            'email' => $this->administrator->email(),
            'password' => 'test-password-secret',
            '_csrf_token' => $tokens['token'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testLogoutRequiresCsrfAndClearsSession(): void
    {
        $tokens = $this->csrfTokens();
        $this->client->request('POST', '/api/login', [
            'email' => $this->administrator->email(),
            'password' => 'test-password-secret',
            '_csrf_token' => $tokens['token'],
        ]);
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/api/logout', ['_csrf_token' => 'invalid-token']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();

        $tokens = $this->csrfTokens();
        $this->client->request('POST', '/api/logout', ['_csrf_token' => $tokens['logoutToken']]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** @return array{token: string, logoutToken: string} */
    private function csrfTokens(): array
    {
        $this->client->request('GET', '/api/auth/csrf');

        return $this->jsonResponse();
    }

    /** @return array<string, mixed> */
    private function jsonResponse(): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }
}
