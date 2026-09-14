<?php

declare(strict_types=1);

namespace App\Tests\Module\Communications\UI\Http;

use App\Module\Clients\Domain\Model\ChannelConnection;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Communications\Application\CommunicationStore;
use App\Module\Communications\Application\Message\ProcessWebhookInbox;
use App\Module\Communications\Application\Message\ProcessWebhookInboxHandler;
use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Application\OrganizationContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ProviderWebhookControllerTest extends WebTestCase
{
    private KernelBrowser $browser;
    private EntityManagerInterface $entityManager;
    private AdministratorAccount $administrator;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->administrator = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Provider webhook', 'provider-webhook-'.bin2hex(random_bytes(4)).'@example.test', 'test-password', 'UTC');
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }
        $this->entityManager->clear();
        parent::tearDown();
    }

    public function testTelegramWebhookUsesConnectionSecretAndProviderUpdateId(): void
    {
        $channel = $this->channel($this->administrator, 'TELEGRAM', '10001', 'telegram-secret');
        $body = '{"update_id":123,"callback_query":{"id":"callback-1","from":{"id":10001},"data":"confirm"}}';
        $url = '/webhooks/communications/telegram/'.$channel->webhookRoutingKey();

        $this->browser->request('POST', $url, [], [], [
            'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'telegram-secret',
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM communication_webhook_inbox WHERE organization_id = ? AND provider = ? AND external_event_id = ?',
            [$this->administrator->organizationId()->toRfc4122(), 'TELEGRAM', '123'],
        ));
    }

    public function testTelegramSecretCannotBeUsedForAnotherTenantConnection(): void
    {
        $this->channel($this->administrator, 'TELEGRAM', '10001', 'tenant-a-secret');
        $other = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Other provider tenant', 'other-provider-'.bin2hex(random_bytes(4)).'@example.test', 'test-password', 'UTC');
        $otherChannel = $this->channel($other, 'TELEGRAM', '10002', 'tenant-b-secret');
        $body = '{"update_id":124,"message":{"from":{"id":10001},"text":"Будем"}}';

        $this->browser->request('POST', '/webhooks/communications/telegram/'.$otherChannel->webhookRoutingKey(), [], [], [
            'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'tenant-a-secret',
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM communication_webhook_inbox WHERE organization_id = ?',
            [$other->organizationId()->toRfc4122()],
        ));
    }

    public function testTelegramStartActivatesOnlyMatchingPendingConnection(): void
    {
        $channel = $this->channel($this->administrator, 'TELEGRAM', 'provisional', 'telegram-secret', 'activation_token');
        $body = '{"update_id":125,"message":{"from":{"id":10003},"text":"/start activation_token"}}';
        $this->browser->request('POST', '/webhooks/communications/telegram/'.$channel->webhookRoutingKey(), [], [], [
            'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'telegram-secret',
            'CONTENT_TYPE' => 'application/json',
        ], $body);
        self::assertResponseIsSuccessful();

        $this->processPendingInbox();

        self::assertTrue($channel->isActive());
        self::assertSame('10003', $channel->address());
    }

    public function testWhatsAppVerificationAndSignedDeliveryWebhook(): void
    {
        $channel = $this->channel($this->administrator, 'WHATSAPP', '79990000001', 'verify-token');
        $url = '/webhooks/communications/whatsapp/'.$channel->webhookRoutingKey();
        $this->browser->request('GET', $url.'?hub.mode=subscribe&hub.verify_token=verify-token&hub.challenge=challenge-123');
        self::assertResponseIsSuccessful();
        self::assertSame('challenge-123', $this->browser->getResponse()->getContent());

        $body = '{"entry":[{"changes":[{"field":"messages","value":{"statuses":[{"id":"wamid.1","status":"delivered","timestamp":"1789344000"}]}}]}]}';
        $signature = 'sha256='.hash_hmac('sha256', $body, 'test-whatsapp-app-secret');
        $this->browser->request('POST', $url, [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        self::assertResponseIsSuccessful();
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM communication_webhook_inbox WHERE organization_id = ? AND provider = ? AND external_event_id = ?',
            [$this->administrator->organizationId()->toRfc4122(), 'WHATSAPP', 'wamid.1:delivered'],
        ));
    }

    private function channel(AdministratorAccount $administrator, string $provider, string $address, string $secret, ?string $activationToken = null): ChannelConnection
    {
        $client = Client::create($administrator->organization(), $provider.' recipient', 'ADULT', null, null);
        $this->entityManager->persist($client);
        $this->entityManager->flush();
        $channel = ChannelConnection::create($client, null, $provider, $address);
        $channel->configureWebhookSecret($secret);
        if (null === $activationToken) {
            $channel->activate();
        } else {
            $channel->startActivation($activationToken, new \DateTimeImmutable('+30 minutes'));
        }
        $this->entityManager->persist($channel);
        $this->entityManager->flush();

        return $channel;
    }

    private function processPendingInbox(): void
    {
        $context = self::getContainer()->get(OrganizationContext::class);
        $store = self::getContainer()->get(CommunicationStore::class);
        $handler = self::getContainer()->get(ProcessWebhookInboxHandler::class);
        foreach ($context->runWith($this->administrator->organizationId(), fn () => $store->pendingWebhooks()) as $inbox) {
            $context->runWith($this->administrator->organizationId(), fn () => $handler(new ProcessWebhookInbox($this->administrator->organizationId(), $inbox->id())));
        }
    }
}
