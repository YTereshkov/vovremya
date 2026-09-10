<?php

declare(strict_types=1);

namespace App\Tests\Module\Communications\UI\Http;

use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ChannelSettingsControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private string $csrf;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $administrator = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Channel settings', 'channels-'.bin2hex(random_bytes(3)).'@example.test', 'test-password', 'UTC');
        $this->client->loginUser($administrator);
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

    public function testSettingsExposeOnlyConfiguredProvidersAndRealCapabilities(): void
    {
        $this->client->request('GET', '/api/communications/channels');
        self::assertResponseIsSuccessful();
        $settings = $this->json();
        self::assertSame('MAX', $settings['defaultProvider']);
        self::assertCount(1, $settings['providers']);
        self::assertSame('MAX', $settings['providers'][0]['provider']);
        self::assertTrue($settings['providers'][0]['capabilities']['supportsButtons']);
        self::assertFalse($settings['providers'][0]['capabilities']['supportsReadStatus']);

        $this->client->jsonRequest('PUT', '/api/communications/channels/default', ['provider' => 'TELEGRAM'], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('PUT', '/api/communications/channels/default', ['provider' => 'MAX'], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseIsSuccessful();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
