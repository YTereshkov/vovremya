<?php

declare(strict_types=1);

namespace App\Tests\Module\Clients\UI\Http;

use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ClientControllerTest extends WebTestCase
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
        $this->administrator = $handler->create('Clients A', 'clients-a@example.test', 'test-password', 'Europe/Moscow');
        $this->otherAdministrator = $handler->create('Clients B', 'clients-b@example.test', 'test-password', 'Europe/Moscow');
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

    public function testNewClientRequiresTheCorrectRecipientAndPrimaryChannel(): void
    {
        $this->send('POST', '/api/clients', [
            'name' => 'Анна Сидорова', 'type' => 'ADULT', 'phone' => null, 'note' => null,
            'contactPerson' => null, 'primaryChannel' => null,
        ]);
        self::assertResponseStatusCodeSame(422);

        $this->send('POST', '/api/clients', [
            'name' => 'Петя Сидоров', 'type' => 'CHILD', 'phone' => null, 'note' => null,
            'contactPerson' => ['name' => 'Анна Сидорова', 'phone' => null],
            'primaryChannel' => ['recipient' => 'CONTACT_PERSON', 'provider' => 'MAX', 'address' => '+7 900 100-00-00'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCreatesSelfRecipientAndContactPersonRecipient(): void
    {
        $adult = $this->send('POST', '/api/clients', [
            'name' => 'Мария Петрова', 'type' => 'ADULT', 'phone' => '+7 900 100-00-00', 'note' => 'После 18:00',
            'contactPerson' => null,
            'primaryChannel' => ['recipient' => 'CLIENT', 'provider' => 'MAX', 'address' => '+7 900 100-00-00'],
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('CLIENT', $adult['channels'][0]['recipientType']);
        self::assertTrue($adult['channels'][0]['primary']);

        $child = $this->send('POST', '/api/clients', [
            'name' => 'Петя Сидоров', 'type' => 'CHILD', 'phone' => null, 'note' => null,
            'contactPerson' => ['name' => 'Анна Сидорова', 'phone' => '+7 900 200-00-00'],
            'primaryChannel' => ['recipient' => 'CONTACT_PERSON', 'provider' => 'TELEGRAM', 'address' => '@anna'],
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Анна Сидорова', $child['channels'][0]['recipientName']);
        self::assertSame('CONTACT_PERSON', $child['channels'][0]['recipientType']);
    }

    public function testCrudSearchAndNestedResources(): void
    {
        $client = $this->createClientRecord('Иван Петров');
        $id = $client['id'];
        $contact = $this->send('POST', "/api/clients/$id/contacts", ['name' => 'Анна Петрова', 'phone' => '+7 900 000-00-00']);
        self::assertResponseStatusCodeSame(201);
        $contactId = $contact['contacts'][0]['id'];

        $withChannel = $this->send('POST', "/api/clients/$id/channels", [
            'contactPersonId' => $contactId, 'provider' => 'WHATSAPP', 'address' => '+7 900 000-00-00', 'primary' => true,
        ]);
        self::assertResponseStatusCodeSame(201);
        $channelId = $this->channelIdByAddress($withChannel, '+7 900 000-00-00');
        self::assertSame($channelId, $withChannel['primaryChannelId']);
        self::assertSame('PENDING', $this->channelByAddress($withChannel, '+7 900 000-00-00')['status']);
        $configured = $this->send('PUT', "/api/clients/$id/channels/$channelId/webhook-secret", ['secret' => 'channel-secret']);
        self::assertSame('PENDING', $this->channelByAddress($configured, '+7 900 000-00-00')['status']);

        $this->client->request('GET', '/api/clients?search='.urlencode('иван'));
        self::assertCount(1, $this->json());
        $this->client->request('GET', '/api/clients?search='.urlencode('%_'));
        self::assertSame([], $this->json());

        $updated = $this->send('PUT', "/api/clients/$id", ['name' => 'Иван Сидоров', 'type' => 'CHILD', 'phone' => null, 'note' => 'Заметка']);
        self::assertSame('Иван Сидоров', $updated['name']);
        $this->send('PUT', "/api/clients/$id/channels/$channelId", ['provider' => 'MAX', 'address' => 'max-address']);
        self::assertResponseIsSuccessful();
        $this->send('DELETE', "/api/clients/$id/contacts/$contactId");
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->json()['channels']);
        self::assertNull($this->json()['primaryChannelId']);

        $this->send('DELETE', "/api/clients/$id");
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', "/api/clients/$id");
        self::assertResponseStatusCodeSame(404);
    }

    public function testTenantIsolationAndForeignPrimaryChannelAreRejected(): void
    {
        $own = $this->createClientRecord('Свой клиент');
        $this->login($this->otherAdministrator);
        $foreign = $this->createClientRecord('Чужой клиент');
        $foreignWithChannel = $this->send('POST', '/api/clients/'.$foreign['id'].'/channels', [
            'contactPersonId' => null, 'provider' => 'MAX', 'address' => 'foreign', 'primary' => true,
        ]);
        $foreignChannelId = $this->channelIdByAddress($foreignWithChannel, 'foreign');

        $this->login($this->administrator);
        $this->client->request('GET', '/api/clients/'.$foreign['id']);
        self::assertResponseStatusCodeSame(404);
        $this->send('PUT', '/api/clients/'.$own['id'].'/primary-channel', ['connectionId' => $foreignChannelId]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/clients');
        self::assertCount(1, $this->json());

        $connection = $this->entityManager->getConnection();
        $connection->createSavepoint('foreign_primary');
        try {
            $connection->executeStatement('UPDATE clients SET primary_channel_id = ? WHERE id = ?', [$foreignChannelId, $own['id']]);
            self::fail('Cross-tenant primary channel foreign key accepted.');
        } catch (ForeignKeyConstraintViolationException) {
            self::addToAssertionCount(1);
        } finally {
            $connection->rollbackSavepoint('foreign_primary');
        }
    }

    public function testMaxActivationLinkAndDisableLifecycleAreTenantScoped(): void
    {
        $client = $this->createClientRecord('Получатель MAX');
        $withChannel = $this->send('POST', '/api/clients/'.$client['id'].'/channels', [
            'contactPersonId' => null, 'provider' => 'MAX', 'address' => '+7 900 000-00-00', 'primary' => true,
        ]);
        $channelId = $this->channelIdByAddress($withChannel, '+7 900 000-00-00');

        $this->send('POST', "/api/clients/{$client['id']}/channels/$channelId/activation");
        self::assertResponseStatusCodeSame(409);
        $this->send('PUT', "/api/clients/{$client['id']}/channels/$channelId/webhook-secret", ['secret' => 'connection-secret']);
        $activation = $this->send('POST', "/api/clients/{$client['id']}/channels/$channelId/activation");
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('https://max.ru/VovremyaTestBot?start=', $activation['url']);
        self::assertNotEmpty($activation['expiresAt']);
        parse_str((string) parse_url($activation['url'], PHP_URL_QUERY), $query);
        self::assertIsString($query['start'] ?? null);

        $context = self::getContainer()->get(\App\Module\Organization\Application\OrganizationContext::class);
        $resolver = self::getContainer()->get(\App\Module\Clients\Application\ChannelConnectionResolver::class);
        $activated = $context->runWith(
            $this->administrator->organizationId(),
            fn () => $resolver->activatePendingChannelForTenant(\Symfony\Component\Uid\Ulid::fromString($channelId), $query['start'], '123456789'),
        );
        self::assertNull($context->runWith(
            $this->administrator->organizationId(),
            fn () => $resolver->activatePendingChannelForTenant(\Symfony\Component\Uid\Ulid::fromString($channelId), $query['start'], '999'),
        ));
        self::assertSame('123456789', $activated?->address());
        self::assertTrue($activated?->isActive());

        $expiredActivation = $this->send('POST', "/api/clients/{$client['id']}/channels/$channelId/activation");
        parse_str((string) parse_url($expiredActivation['url'], PHP_URL_QUERY), $expiredQuery);
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE channel_connections SET activation_expires_at = clock_timestamp() - INTERVAL '1 minute' WHERE id = ?",
            [$channelId],
        );
        self::assertNull($context->runWith(
            $this->administrator->organizationId(),
            fn () => $resolver->activatePendingChannelForTenant(\Symfony\Component\Uid\Ulid::fromString($channelId), (string) $expiredQuery['start'], '123456789'),
        ));

        $disabled = $this->send('POST', "/api/clients/{$client['id']}/channels/$channelId/deactivate");
        self::assertSame('DISABLED', $this->channelByAddress($disabled, '123456789')['status']);

        $this->login($this->otherAdministrator);
        $this->send('POST', "/api/clients/{$client['id']}/channels/$channelId/activation");
        self::assertResponseStatusCodeSame(404);
    }

    public function testValidationAndCsrf(): void
    {
        $this->client->jsonRequest('POST', '/api/clients', ['name' => 'Test']);
        self::assertResponseStatusCodeSame(403);
        $this->send('POST', '/api/clients', [
            'name' => 'Test', 'type' => 'CHILD', 'contactPerson' => null,
            'primaryChannel' => ['recipient' => 'CONTACT_PERSON', 'provider' => 'MAX', 'address' => 'test'],
        ]);
        self::assertResponseStatusCodeSame(422);
        $this->send('POST', '/api/clients', ['name' => '', 'type' => 'UNKNOWN']);
        self::assertResponseStatusCodeSame(422);
    }

    /** @return array<string, mixed> */
    private function createClientRecord(string $name): array
    {
        return $this->send('POST', '/api/clients', [
            'name' => $name, 'type' => 'ADULT', 'phone' => '+7 999 000-00-00', 'note' => null,
            'contactPerson' => null,
            'primaryChannel' => ['recipient' => 'CLIENT', 'provider' => 'MAX', 'address' => '+7 999 000-00-00'],
        ]);
    }

    /** @param array<string, mixed> $client */
    private function channelIdByAddress(array $client, string $address): string
    {
        return $this->channelByAddress($client, $address)['id'];
    }

    /** @param array<string, mixed> $client
     *  @return array<string, mixed>
     */
    private function channelByAddress(array $client, string $address): array
    {
        foreach ($client['channels'] as $channel) {
            if ($address === $channel['address']) {
                return $channel;
            }
        }

        throw new \RuntimeException('Expected channel not found.');
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

        return 204 === $this->client->getResponse()->getStatusCode() ? [] : $this->json();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
