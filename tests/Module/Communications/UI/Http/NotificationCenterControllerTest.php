<?php

declare(strict_types=1);

namespace App\Tests\Module\Communications\UI\Http;

use App\Module\Clients\Domain\Model\ChannelConnection;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Clients\Domain\Model\ContactPerson;
use App\Module\Communications\Application\NotificationOutbox;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Application\OrganizationContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NotificationCenterControllerTest extends WebTestCase
{
    private KernelBrowser $browser;
    private EntityManagerInterface $entityManager;
    private AdministratorAccount $administrator;
    private string $csrf;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->administrator = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Notifications', 'notifications-'.bin2hex(random_bytes(4)).'@example.test', 'test-password', 'Europe/Moscow');
        $this->browser->loginUser($this->administrator);
        $this->browser->request('GET', '/api/auth/csrf');
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

    public function testFailedDeliveryIsVisibleCanBeReadAndRetried(): void
    {
        $message = $this->failedMessage('Петя Иванов');

        $this->browser->request('GET', '/api/notifications');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->json()['unreadCount']);
        self::assertSame('delivery-failed', $this->json()['items'][0]['type']);

        $this->browser->request('GET', '/api/notifications/delivery');
        self::assertResponseIsSuccessful();
        self::assertSame('Петя Иванов', $this->json()['items'][0]['clientName']);
        self::assertSame('FAILED', $this->json()['items'][0]['status']);
        self::assertFalse($this->json()['items'][0]['capabilities']['supportsReadStatus']);

        $this->browser->jsonRequest('PUT', '/api/notifications/read-all', [], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseIsSuccessful();
        $this->browser->jsonRequest('PUT', '/api/notifications/read-all', [], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM administrator_notification_states WHERE organization_id = :organization AND administrator_id = :administrator',
            ['organization' => $this->administrator->organizationId()->toRfc4122(), 'administrator' => $this->administrator->id()->toRfc4122()],
        ));
        $this->browser->request('GET', '/api/notifications');
        self::assertSame(0, $this->json()['unreadCount']);

        $this->browser->jsonRequest('POST', '/api/communications/messages/'.$message->id()->toRfc4122().'/retry', [], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseIsSuccessful();
        self::assertSame('PENDING', $this->json()['status']);
        $this->browser->request('GET', '/api/notifications/delivery');
        self::assertSame([], $this->json()['items']);
    }

    public function testForeignTenantCannotReadOrRetryMessage(): void
    {
        $message = $this->failedMessage('Tenant A client');
        $foreign = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Foreign notifications', 'foreign-notifications-'.bin2hex(random_bytes(4)).'@example.test', 'test-password', 'UTC');
        $this->browser->loginUser($foreign);
        $this->browser->request('GET', '/api/auth/csrf');
        $csrf = $this->json()['mutationToken'];

        $this->browser->request('GET', '/api/notifications');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json()['items']);
        $this->browser->request('GET', '/api/notifications/delivery');
        self::assertSame([], $this->json()['items']);
        $this->browser->jsonRequest('POST', '/api/communications/messages/'.$message->id()->toRfc4122().'/retry', [], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeliveryReportKeepsClientAndContactRecipientSeparate(): void
    {
        $this->failedMessage('Петя Иванов', 'Анна Иванова');

        $this->browser->request('GET', '/api/notifications/delivery');
        self::assertResponseIsSuccessful();
        self::assertSame('Петя Иванов', $this->json()['items'][0]['clientName']);
        self::assertSame('Анна Иванова', $this->json()['items'][0]['contactName']);
    }

    private function failedMessage(string $clientName, ?string $contactName = null): \App\Module\Communications\Domain\Model\OutboundMessage
    {
        $client = Client::create($this->administrator->organization(), $clientName, 'CHILD', '+79990000000', null);
        $contact = null === $contactName ? null : ContactPerson::create($client, $contactName, '+79991111111');
        $channel = ChannelConnection::create($client, $contact, 'MAX', '123456789');
        $channel->activate();
        $this->entityManager->persist($client);
        if (null !== $contact) {
            $this->entityManager->persist($contact);
        }
        $this->entityManager->persist($channel);
        $this->entityManager->flush();
        $client->selectPrimaryChannel($channel);
        $this->entityManager->flush();
        $context = self::getContainer()->get(OrganizationContext::class);
        $message = $context->runWith($this->administrator->organizationId(), fn () => self::getContainer()->get(NotificationOutbox::class)->queue(
            'URGENT_CANCELLATION', $channel->id(), CommunicationProvider::MAX, $channel->address(), 'Занятие отменено.', dedupeKey: 'failed-'.bin2hex(random_bytes(4)),
        ));
        $message->fail('Provider unavailable');
        $this->entityManager->flush();

        return $message;
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
