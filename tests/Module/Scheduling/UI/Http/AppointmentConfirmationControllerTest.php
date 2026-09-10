<?php

declare(strict_types=1);

namespace App\Tests\Module\Scheduling\UI\Http;

use App\Module\Catalog\Domain\Model\Service;
use App\Module\Clients\Domain\Model\ChannelConnection;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Clients\Domain\Model\ContactPerson;
use App\Module\Communications\Domain\Model\OutboundMessage;
use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Scheduling\Application\ConfirmationResponseConsumer;
use App\Module\Scheduling\Application\AppointmentConfirmationService;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\AppointmentConfirmationRequest;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Communications\Domain\Model\NormalizedWebhookEvent;
use App\Module\Workforce\Domain\Model\Specialist;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Ulid;

final class AppointmentConfirmationControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AdministratorAccount $administrator;
    private Appointment $appointment;
    private ChannelConnection $channel;
    private string $csrf;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->administrator = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Confirmations', 'confirmations-'.bin2hex(random_bytes(3)).'@example.test', 'test-password', 'Europe/Moscow');
        $specialist = new Specialist(new Ulid(), $this->administrator->organization(), 'Юлия', 'Логопед');
        $client = Client::create($this->administrator->organization(), 'Петя', 'CHILD', null, null);
        $service = Service::create($this->administrator->organization(), 'Диагностика', 60, null, null);
        $service->changeConfirmationTemplate('{contact_name}: {service}, {date} в {time}.');
        $this->channel = ChannelConnection::create($client, null, 'MAX', '123456789');
        $this->channel->activate();
        $this->appointment = Appointment::create(
            $this->administrator->organization(),
            $specialist->id(),
            $client->id(),
            $service->id(),
            $service->name(),
            60,
            null,
            null,
            60,
            new \DateTimeImmutable('+1 day 15:00', new \DateTimeZone('Europe/Moscow')),
        );
        foreach ([$specialist, $client, $service, $this->appointment] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->entityManager->persist($this->channel);
        $this->entityManager->flush();
        $client->selectPrimaryChannel($this->channel);
        $this->entityManager->flush();
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

    public function testRequestIsIdempotentAndUsesOpaqueSingleUseActions(): void
    {
        $this->requestConfirmation();
        self::assertResponseStatusCodeSame(201);
        self::assertSame('PENDING', $this->json()['status']);
        self::assertSame(1, $this->countRows('appointment_confirmation_requests'));
        self::assertSame(2, $this->countRows('appointment_confirmation_actions'));
        self::assertSame(1, $this->countRows('communication_outbox'));

        $this->client->request('GET', '/api/appointments/'.$this->appointment->id()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSame('PENDING', $this->json()['confirmationStatus']);

        $outbound = $this->entityManager->getRepository(OutboundMessage::class)->findOneBy([]);
        self::assertInstanceOf(OutboundMessage::class, $outbound);
        self::assertStringContainsString('Петя: Диагностика', $outbound->body());
        $actions = array_map(static fn (array $row): string => $row[0]['action'], $outbound->buttons());
        self::assertMatchesRegularExpression('/^confirmation:[0-9a-f-]{36}:[A-Za-z0-9_-]+$/', $actions[0]);
        self::assertStringNotContainsString($actions[0], json_encode($this->entityManager->getConnection()->fetchFirstColumn('SELECT token_hash FROM appointment_confirmation_actions'), JSON_THROW_ON_ERROR));

        $this->requestConfirmation();
        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $this->countRows('appointment_confirmation_requests'));
        self::assertSame(1, $this->countRows('communication_outbox'));
    }

    public function testVerifiedRecipientCanConfirmOnlyOnce(): void
    {
        $this->requestConfirmation();
        $outbound = $this->entityManager->getRepository(OutboundMessage::class)->findOneBy([]);
        self::assertInstanceOf(OutboundMessage::class, $outbound);
        $confirmAction = $outbound->buttons()[0][0]['action'];
        $cannotAction = $outbound->buttons()[1][0]['action'];
        $consumer = self::getContainer()->get(ConfirmationResponseConsumer::class);

        $consumer->consume($this->event($confirmAction, $this->channel->id(), 'callback-1'));
        $this->entityManager->clear();
        $request = $this->entityManager->getRepository(AppointmentConfirmationRequest::class)->findOneBy([]);
        self::assertSame('CONFIRMED', $request?->status()->value);
        self::assertInstanceOf(AppointmentConfirmationRequest::class, $request);
        self::getContainer()->get(AppointmentConfirmationService::class)->remind(
            $this->entityManager->find(Appointment::class, $this->appointment->id()),
            $request,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
        self::assertSame(1, $this->countRows('communication_outbox'));

        $consumer->consume($this->event($cannotAction, $this->channel->id(), 'callback-2'));
        $this->entityManager->clear();
        $request = $this->entityManager->getRepository(AppointmentConfirmationRequest::class)->findOneBy([]);
        self::assertSame('CONFIRMED', $request?->status()->value);
    }

    public function testLateResponseReplacesNoResponseButKeepsSingleUseSemantics(): void
    {
        $this->requestConfirmation();
        $outbound = $this->entityManager->getRepository(OutboundMessage::class)->findOneBy([]);
        $request = $this->entityManager->getRepository(AppointmentConfirmationRequest::class)->findOneBy([]);
        self::assertInstanceOf(OutboundMessage::class, $outbound);
        self::assertInstanceOf(AppointmentConfirmationRequest::class, $request);
        $request->markNoResponse(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->entityManager->flush();

        $consumer = self::getContainer()->get(ConfirmationResponseConsumer::class);
        $consumer->consume($this->event($outbound->buttons()[0][0]['action'], $this->channel->id(), 'late-callback'));
        $this->entityManager->clear();
        self::assertSame('CONFIRMED', $this->entityManager->getRepository(AppointmentConfirmationRequest::class)->findOneBy([])?->status()->value);

        $consumer->consume($this->event($outbound->buttons()[1][0]['action'], $this->channel->id(), 'second-late-callback'));
        $this->entityManager->clear();
        self::assertSame('CONFIRMED', $this->entityManager->getRepository(AppointmentConfirmationRequest::class)->findOneBy([])?->status()->value);
    }

    public function testWrongChannelAndForeignAppointmentAreRejected(): void
    {
        $this->requestConfirmation();
        $outbound = $this->entityManager->getRepository(OutboundMessage::class)->findOneBy([]);
        self::assertInstanceOf(OutboundMessage::class, $outbound);
        $other = ChannelConnection::create($this->entityManager->find(Client::class, $this->appointment->clientId()), null, 'MAX', '987654321');
        $other->activate();
        $this->entityManager->persist($other);
        $this->entityManager->flush();
        self::getContainer()->get(ConfirmationResponseConsumer::class)->consume($this->event($outbound->buttons()[0][0]['action'], $other->id(), 'wrong-channel'));
        $this->entityManager->clear();
        self::assertSame('PENDING', $this->entityManager->getRepository(AppointmentConfirmationRequest::class)->findOneBy([])?->status()->value);

        $foreign = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Foreign confirmation', 'foreign-confirmation-'.bin2hex(random_bytes(3)).'@example.test', 'test-password', 'UTC');
        $this->client->loginUser($foreign);
        $this->client->request('GET', '/api/appointments/'.$this->appointment->id()->toRfc4122().'/confirmation');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOptionalContactPersonCanBeTheNotificationRecipient(): void
    {
        $client = $this->entityManager->find(Client::class, $this->appointment->clientId());
        self::assertInstanceOf(Client::class, $client);
        $contact = ContactPerson::create($client, 'Анна', null);
        $channel = ChannelConnection::create($client, $contact, 'MAX', 'contact-recipient');
        $channel->activate();
        $this->entityManager->persist($contact);
        $this->entityManager->persist($channel);
        $this->entityManager->flush();
        $client->selectPrimaryChannel($channel);
        $this->entityManager->flush();

        $this->requestConfirmation();
        self::assertResponseStatusCodeSame(201);
        $outbound = $this->entityManager->getRepository(OutboundMessage::class)->findOneBy([]);
        self::assertInstanceOf(OutboundMessage::class, $outbound);
        self::assertSame($channel->id()->toRfc4122(), $outbound->channelConnectionId()->toRfc4122());
        self::assertStringContainsString('Анна: Диагностика', $outbound->body());
    }

    private function requestConfirmation(): void
    {
        $this->client->jsonRequest('POST', '/api/appointments/'.$this->appointment->id()->toRfc4122().'/confirmation', [], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
    }

    private function event(string $action, Ulid $channelId, string $id): NormalizedWebhookEvent
    {
        return NormalizedWebhookEvent::create(
            $this->administrator->organization(),
            $channelId,
            CommunicationProvider::MAX,
            $id,
            ['kind' => 'BUTTON', 'action' => $action, 'userId' => '123456789'],
        );
    }

    private function countRows(string $table): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
