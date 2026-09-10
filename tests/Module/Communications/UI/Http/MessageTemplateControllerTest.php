<?php

declare(strict_types=1);

namespace App\Tests\Module\Communications\UI\Http;

use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MessageTemplateControllerTest extends WebTestCase
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
        $this->administrator = $handler->create('Templates A', 'templates-a-'.bin2hex(random_bytes(3)).'@example.test', 'test-password', 'UTC');
        $this->otherAdministrator = $handler->create('Templates B', 'templates-b-'.bin2hex(random_bytes(3)).'@example.test', 'test-password', 'UTC');
        $this->login($this->administrator);
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }
        $this->entityManager->clear();
        parent::tearDown();
    }

    public function testDefaultsCanBeChangedAndRestored(): void
    {
        $this->client->request('GET', '/api/communications/templates');
        self::assertResponseIsSuccessful();
        $defaults = $this->json();
        self::assertCount(3, $defaults);
        self::assertSame(['CONFIRMATION', 'TRANSFER', 'FREE_WINDOW'], array_column($defaults, 'type'));
        self::assertTrue($defaults[0]['isDefault']);

        $custom = 'Здравствуйте, {contact_name}. {date} в {time} — {service} для {client_name}.';
        $saved = $this->send('PUT', '/api/communications/templates/confirmation', ['body' => $custom]);
        self::assertResponseIsSuccessful();
        self::assertSame($custom, $saved['body']);
        self::assertFalse($saved['isDefault']);

        $restored = $this->send('DELETE', '/api/communications/templates/confirmation');
        self::assertResponseIsSuccessful();
        self::assertTrue($restored['isDefault']);
        self::assertNotSame($custom, $restored['body']);
    }

    public function testTemplateValidationAndTenantIsolation(): void
    {
        $this->send('PUT', '/api/communications/templates/confirmation', ['body' => 'Для A: {date}.']);
        self::assertResponseIsSuccessful();

        $this->send('PUT', '/api/communications/templates/confirmation', ['body' => 'Ошибка: {{date}}.']);
        self::assertResponseStatusCodeSame(422);
        $this->send('PUT', '/api/communications/templates/confirmation', ['body' => 'Ошибка: {unknown}.']);
        self::assertResponseStatusCodeSame(422);

        $this->login($this->otherAdministrator);
        $this->client->request('GET', '/api/communications/templates');
        $other = $this->json();
        self::assertTrue($other[0]['isDefault']);
        self::assertStringNotContainsString('Для A', $other[0]['body']);
    }

    private function login(AdministratorAccount $administrator): void
    {
        $this->client->loginUser($administrator);
        $this->client->request('GET', '/api/auth/csrf');
        $this->csrf = $this->json()['mutationToken'];
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed>
     */
    private function send(string $method, string $url, array $data = []): array
    {
        $this->client->jsonRequest($method, $url, $data, ['HTTP_X_CSRF_TOKEN' => $this->csrf]);

        return $this->json();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
