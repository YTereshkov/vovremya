<?php

declare(strict_types=1);

namespace App\Tests\Module\Communications\UI\Http;

use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ConfirmationSettingsControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private string $csrf;
    private AdministratorAccount $administrator;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->administrator = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Confirmation settings', 'confirmation-settings-'.bin2hex(random_bytes(3)).'@example.test', 'test-password', 'Europe/Moscow');
        $this->client->loginUser($this->administrator);
        $this->client->request('GET', '/api/auth/csrf');
        $this->csrf = $this->json()['mutationToken'];
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function testDefaultsCanBeChanged(): void
    {
        $this->client->request('GET', '/api/communications/confirmation-settings');
        self::assertResponseIsSuccessful();
        self::assertSame('14:00', $this->json()['requestTime']);

        $payload = [
            'requestTime' => '13:30',
            'noResponseTime' => '17:00',
            'reminderEnabled' => true,
            'reminderLeadMinutes' => 90,
            'reminderNotBefore' => '08:00',
            'quietHoursStart' => '22:00',
            'quietHoursEnd' => '07:30',
        ];
        $this->client->jsonRequest('PUT', '/api/communications/confirmation-settings', $payload, ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseIsSuccessful();
        self::assertSame($payload, $this->json());

        $this->entityManager->clear();
        $this->client->request('GET', '/api/communications/confirmation-settings');
        self::assertResponseIsSuccessful();
        self::assertSame('13:30', $this->json()['requestTime']);

        $invalid = $payload;
        $invalid['noResponseTime'] = '12:00';
        $this->client->jsonRequest('PUT', '/api/communications/confirmation-settings', $invalid, ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseStatusCodeSame(422);

        $foreign = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Other confirmation settings', 'other-confirmation-settings-'.bin2hex(random_bytes(3)).'@example.test', 'test-password', 'UTC');
        $this->client->loginUser($foreign);
        $this->client->request('GET', '/api/communications/confirmation-settings');
        self::assertResponseIsSuccessful();
        self::assertSame('14:00', $this->json()['requestTime']);
    }

    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
