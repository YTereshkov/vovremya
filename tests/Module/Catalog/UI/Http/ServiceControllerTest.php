<?php

declare(strict_types=1);

namespace App\Tests\Module\Catalog\UI\Http;

use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ServiceControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AdministratorAccount $administrator;
    private AdministratorAccount $otherAdministrator;
    private string $csrf;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $handler = self::getContainer()->get(CreateAdministratorHandler::class);
        $this->administrator = $handler->create('Catalog A', 'catalog-a@example.test', 'test-password', 'Europe/Moscow');
        $this->otherAdministrator = $handler->create('Catalog B', 'catalog-b@example.test', 'test-password', 'Europe/Moscow');
        $this->client->loginUser($this->administrator);
        $this->client->request('GET', '/api/auth/csrf');
        $this->csrf = $this->json()['mutationToken'];
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }
        $this->entityManager->clear();
        parent::tearDown();
    }

    public function testCrudUsesSoftDeleteAndActiveList(): void
    {
        $service = $this->send('POST', '/api/services', $this->payload('Диагностика', 60, 45, 90));
        self::assertResponseStatusCodeSame(201);
        self::assertSame(60, $service['defaultDurationMinutes']);

        $url = '/api/services/'.$service['id'];
        $updated = $this->send('PUT', $url, $this->payload('Первичная диагностика', 80, 60, 90));
        self::assertResponseIsSuccessful();
        self::assertSame('Первичная диагностика', $updated['name']);

        $this->send('DELETE', $url);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/services');
        self::assertSame([], $this->json());
        self::assertNotNull($this->entityManager->getConnection()->fetchOne('SELECT deleted_at FROM services WHERE id = ?', [$service['id']]));
    }

    public function testValidationCsrfAndTenantIsolation(): void
    {
        $this->client->jsonRequest('POST', '/api/services', $this->payload('Test', 60, 45, 90));
        self::assertResponseStatusCodeSame(403);
        $this->send('POST', '/api/services', $this->payload('Test', 30, 45, 90));
        self::assertResponseStatusCodeSame(422);

        $this->client->loginUser($this->otherAdministrator);
        $this->client->request('GET', '/api/auth/csrf');
        $otherCsrf = $this->json()['mutationToken'];
        $this->csrf = $otherCsrf;
        $foreign = $this->send('POST', '/api/services', $this->payload('Foreign', 60, 45, 90));
        self::assertResponseStatusCodeSame(201);

        $this->client->loginUser($this->administrator);
        $this->client->request('GET', '/api/auth/csrf');
        $this->csrf = $this->json()['mutationToken'];
        $this->client->request('GET', '/api/services/'.$foreign['id']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/services');
        self::assertSame([], $this->json());
    }

    public function testOptionalDurationBoundsRoundtrip(): void
    {
        $service = $this->send('POST', '/api/services', $this->payload('Консультация', 45, null, null));

        self::assertResponseStatusCodeSame(201);
        self::assertNull($service['minimumDurationMinutes']);
        self::assertNull($service['maximumDurationMinutes']);
    }

    /** @return array<string, mixed> */
    private function payload(string $name, int $default, ?int $minimum, ?int $maximum): array
    {
        return ['name' => $name, 'defaultDurationMinutes' => $default, 'minimumDurationMinutes' => $minimum, 'maximumDurationMinutes' => $maximum];
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed>
     */
    private function send(string $method, string $url, array $data = []): array
    {
        $this->client->jsonRequest($method, $url, $data, ['HTTP_X_CSRF_TOKEN' => $this->csrf]);

        return 204 === $this->client->getResponse()->getStatusCode() ? [] : $this->json();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
