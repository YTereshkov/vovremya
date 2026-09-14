<?php

declare(strict_types=1);

namespace App\Tests\Module\Communications\Application;

use App\Module\Clients\Domain\Model\ChannelConnection;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Communications\Application\CommunicationStore;
use App\Module\Communications\Application\UnsupportedTextResponseConsumer;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Communications\Domain\Model\NormalizedWebhookEvent;
use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Organization\Application\OrganizationContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UnsupportedTextResponseConsumerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }
        $this->entityManager->clear();
        parent::tearDown();
    }

    #[DataProvider('providers')]
    public function testUnsupportedTextQueuesButtonGuidanceForVerifiedSender(CommunicationProvider $provider, string $address): void
    {
        $administrator = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Text response', 'text-response-'.bin2hex(random_bytes(4)).'@example.test', 'test-password', 'UTC');
        $client = Client::create($administrator->organization(), 'Recipient', 'ADULT', null, null);
        $this->entityManager->persist($client);
        $this->entityManager->flush();
        $channel = ChannelConnection::create($client, null, $provider->value, $address);
        $channel->activate();
        $this->entityManager->persist($channel);
        $this->entityManager->flush();
        $event = NormalizedWebhookEvent::create($administrator->organization(), $channel->id(), $provider, 'text-1', [
            'kind' => 'TEXT_UNSUPPORTED', 'userId' => $address,
        ]);
        $context = self::getContainer()->get(OrganizationContext::class);

        $context->runWith($administrator->organizationId(), fn () => self::getContainer()->get(UnsupportedTextResponseConsumer::class)->consume($event));

        $messages = $context->runWith($administrator->organizationId(), fn () => self::getContainer()->get(CommunicationStore::class)->pendingOutbound());
        self::assertCount(1, $messages);
        self::assertSame('Для работы с расписанием используйте кнопки в сообщении.', $messages[0]->body());
    }

    public function testUnsupportedTextFromAnotherRecipientIsIgnored(): void
    {
        $administrator = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Wrong text sender', 'wrong-text-'.bin2hex(random_bytes(4)).'@example.test', 'test-password', 'UTC');
        $client = Client::create($administrator->organization(), 'Recipient', 'ADULT', null, null);
        $this->entityManager->persist($client);
        $this->entityManager->flush();
        $channel = ChannelConnection::create($client, null, 'TELEGRAM', '10001');
        $channel->activate();
        $this->entityManager->persist($channel);
        $this->entityManager->flush();
        $event = NormalizedWebhookEvent::create($administrator->organization(), $channel->id(), CommunicationProvider::TELEGRAM, 'text-wrong', [
            'kind' => 'TEXT_UNSUPPORTED', 'userId' => '99999',
        ]);
        $context = self::getContainer()->get(OrganizationContext::class);

        $context->runWith($administrator->organizationId(), fn () => self::getContainer()->get(UnsupportedTextResponseConsumer::class)->consume($event));

        self::assertSame([], $context->runWith($administrator->organizationId(), fn () => self::getContainer()->get(CommunicationStore::class)->pendingOutbound()));
    }

    /** @return iterable<string, array{CommunicationProvider, string}> */
    public static function providers(): iterable
    {
        yield 'Telegram' => [CommunicationProvider::TELEGRAM, '10001'];
        yield 'WhatsApp' => [CommunicationProvider::WHATSAPP, '79990000001'];
    }
}
