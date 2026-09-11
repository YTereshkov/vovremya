<?php

declare(strict_types=1);

namespace App\Tests\Module\Waiting\UI\Http;

use App\Module\Catalog\Domain\Model\Service;
use App\Module\Clients\Domain\Model\ChannelConnection;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Clients\Domain\Model\ClientAbsence;
use App\Module\Clients\Domain\Model\ClientAbsenceMode;
use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Communications\Domain\Model\NormalizedWebhookEvent;
use App\Module\Communications\Domain\Model\OutboundMessage;
use App\Module\Scheduling\Application\ScheduleAllocationStore;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\AppointmentResultStatus;
use App\Module\Scheduling\Domain\Model\ScheduleAllocation;
use App\Module\Waiting\Application\FreeWindowStore;
use App\Module\Waiting\Application\FreeWindowOfferResponseConsumer;
use App\Module\Waiting\Domain\Model\FreeWindow;
use App\Module\Waiting\Domain\Model\FreeWindowOffer;
use App\Module\Workforce\Domain\Model\Specialist;
use App\Module\Workforce\Domain\Model\SpecialistWeeklyHours;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Ulid;

final class WaitingListControllerTest extends WebTestCase
{
    private KernelBrowser $browser;
    private EntityManagerInterface $entityManager;
    private AdministratorAccount $administrator;
    private Specialist $specialist;
    private Service $service;
    private Client $waitingClient;
    private Client $laterClient;
    private ChannelConnection $waitingChannel;
    private ChannelConnection $laterChannel;
    private FreeWindow $window;
    private Appointment $laterAppointment;
    private \DateTimeImmutable $windowStart;
    private string $csrf;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->administrator = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Waiting', 'waiting-'.bin2hex(random_bytes(3)).'@example.test', 'test-password', 'Europe/Moscow');
        $week = SpecialistWeeklyHours::emptyWeek();
        foreach ($week as $index => $day) {
            $week[$index] = ['weekday' => $day['weekday'], 'enabled' => true, 'work' => ['start' => '09:00', 'end' => '20:00'], 'lunch' => null];
        }
        $this->specialist = new Specialist(new Ulid(), $this->administrator->organization(), 'Юлия', 'Логопед');
        $this->specialist->setWeeklyHours($week);
        $this->service = Service::create($this->administrator->organization(), 'Логопедическое занятие', 45, null, null);
        $sourceClient = Client::create($this->administrator->organization(), 'Источник', 'CHILD', null, null);
        $this->waitingClient = Client::create($this->administrator->organization(), 'Маша Петрова', 'CHILD', null, null);
        $this->laterClient = Client::create($this->administrator->organization(), 'Анна Петрова', 'ADULT', null, null);
        $this->windowStart = new \DateTimeImmutable('next friday 17:00', new \DateTimeZone('Europe/Moscow'));
        $source = $this->appointment($sourceClient, $this->windowStart);
        $source->recordResult(AppointmentResultStatus::CancelledByClient, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 12, false, null);
        $this->laterAppointment = $this->appointment($this->laterClient, $this->windowStart->modify('+1 hour'));
        foreach ([$this->specialist, $this->service, $sourceClient, $this->waitingClient, $this->laterClient, $source, $this->laterAppointment] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->waitingChannel = ChannelConnection::create($this->waitingClient, null, 'MAX', 'waiting-'.new Ulid());
        $this->waitingChannel->activate();
        $this->laterChannel = ChannelConnection::create($this->laterClient, null, 'MAX', 'later-'.new Ulid());
        $this->laterChannel->activate();
        $this->entityManager->persist($this->waitingChannel);
        $this->entityManager->persist($this->laterChannel);
        $this->entityManager->flush();
        $this->waitingClient->selectPrimaryChannel($this->waitingChannel);
        $this->laterClient->selectPrimaryChannel($this->laterChannel);
        $this->entityManager->flush();
        $this->window = FreeWindow::create(
            $this->administrator->organization(), $source->id(), $this->specialist->id(), $this->service->id(), $this->service->name(), 45,
            $source->startsAt(), $source->endsAt(), new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
        $this->browser->loginUser($this->administrator);
        $this->browser->request('GET', '/api/auth/csrf');
        $this->csrf = $this->json()['mutationToken'];
        self::getContainer()->get(FreeWindowStore::class)->save($this->window);
        self::getContainer()->get(ScheduleAllocationStore::class)->save(ScheduleAllocation::forAppointment(
            $this->administrator->organization(), $this->specialist->id(), $this->laterAppointment->id(), $this->laterAppointment->startsAt(), $this->laterAppointment->endsAt(),
        ));
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function testAdministratorCanConfigureUpdateAndEndWaitingConditions(): void
    {
        $saved = $this->saveWaiting($this->waitingClient, true, [
            ['weekday' => (int) $this->windowStart->format('N'), 'startTime' => '15:00', 'endTime' => '19:00'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame($this->service->id()->toRfc4122(), $saved['service']['id']);
        self::assertSame(45, $saved['service']['durationMinutes']);
        self::assertSame(2, $saved['requiredFrequency']);
        self::assertTrue($saved['readyForOneOff']);
        self::assertSame('Могут приехать быстро', $saved['comment']);

        $updated = $this->saveWaiting($this->waitingClient, false, [
            ['weekday' => (int) $this->windowStart->format('N'), 'startTime' => '16:00', 'endTime' => null],
        ]);
        self::assertSame($saved['id'], $updated['id']);
        self::assertFalse($updated['readyForOneOff']);
        self::assertSame('16:00', $updated['availability'][0]['startTime']);
        self::assertNull($updated['availability'][0]['endTime']);
        self::assertSame(1, $this->countRows('waiting_list_entries'));
        self::assertSame(1, $this->countRows('waiting_list_availability'));

        $this->browser->request('DELETE', '/api/clients/'.$this->waitingClient->id()->toRfc4122().'/waiting-list', server: ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseStatusCodeSame(204);
        $this->browser->request('GET', '/api/clients/'.$this->waitingClient->id()->toRfc4122().'/waiting-list');
        self::assertResponseIsSuccessful();
        self::assertNull($this->json());
    }

    public function testMatchingPrioritizesLaterAppointmentAndThenWaitingClients(): void
    {
        $this->saveWaiting($this->waitingClient, true, [[
            'weekday' => (int) $this->windowStart->format('N'), 'startTime' => '15:00', 'endTime' => '19:00',
        ]]);

        $this->browser->request('GET', '/api/free-windows/'.$this->window->id()->toRfc4122().'/candidates');
        self::assertResponseIsSuccessful();
        $result = $this->json();
        self::assertSame($this->laterAppointment->id()->toRfc4122(), $result['moveEarlier'][0]['appointmentId']);
        self::assertSame('Анна Петрова', $result['moveEarlier'][0]['client']['name']);
        self::assertSame('Маша Петрова', $result['waitingClients'][0]['client']['name']);
        self::assertStringContainsString('15:00–19:00', $result['waitingClients'][0]['availability']);
    }

    public function testCurrentServiceDurationAndAvailabilityControlWaitingMatch(): void
    {
        $this->saveWaiting($this->waitingClient, true, [[
            'weekday' => (int) $this->windowStart->format('N'), 'startTime' => '17:30', 'endTime' => null,
        ]]);
        $this->browser->request('GET', '/api/free-windows/'.$this->window->id()->toRfc4122().'/candidates');
        self::assertSame([], $this->json()['waitingClients']);

        $this->saveWaiting($this->waitingClient, true, [[
            'weekday' => (int) $this->windowStart->format('N'), 'startTime' => '15:00', 'endTime' => '19:00',
        ]]);
        $service = $this->entityManager->find(Service::class, $this->service->id());
        self::assertInstanceOf(Service::class, $service);
        $service->change($service->name(), 60, null, null);
        $this->entityManager->flush();
        $this->browser->request('GET', '/api/free-windows/'.$this->window->id()->toRfc4122().'/candidates');
        self::assertSame([], $this->json()['waitingClients']);
        self::assertCount(1, $this->json()['moveEarlier']);
    }

    public function testForeignTenantCannotReadOrChangeWaitingConditions(): void
    {
        $this->saveWaiting($this->waitingClient, true, [[
            'weekday' => (int) $this->windowStart->format('N'), 'startTime' => '15:00', 'endTime' => null,
        ]]);
        $foreign = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Foreign waiting', 'foreign-waiting-'.bin2hex(random_bytes(3)).'@example.test', 'test-password', 'UTC');
        $this->browser->loginUser($foreign);
        $this->browser->request('GET', '/api/clients/'.$this->waitingClient->id()->toRfc4122().'/waiting-list');
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('GET', '/api/free-windows/'.$this->window->id()->toRfc4122().'/candidates');
        self::assertResponseStatusCodeSame(404);
    }

    public function testClientAbsenceExcludesWaitingMatch(): void
    {
        $this->saveWaiting($this->waitingClient, true, [[
            'weekday' => (int) $this->windowStart->format('N'), 'startTime' => '15:00', 'endTime' => '19:00',
        ]]);
        $client = $this->entityManager->find(Client::class, $this->waitingClient->id());
        self::assertInstanceOf(Client::class, $client);
        $date = new \DateTimeImmutable($this->windowStart->format('Y-m-d'), new \DateTimeZone('UTC'));
        $absence = ClientAbsence::create($client, $date, $date, null, ClientAbsenceMode::KeepPermanentPlace, false, false, new \DateTimeImmutable());
        $this->entityManager->persist($absence);
        $this->entityManager->flush();

        $this->browser->request('GET', '/api/free-windows/'.$this->window->id()->toRfc4122().'/candidates');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json()['waitingClients']);
    }

    public function testWaitingClientOfferReservesWindowAndAcceptanceCreatesOneOffAppointment(): void
    {
        $entry = $this->saveWaiting($this->waitingClient, true, [[
            'weekday' => (int) $this->windowStart->format('N'), 'startTime' => '15:00', 'endTime' => '19:00',
        ]]);
        $offer = $this->offer('WAITING_LIST', $entry['id']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('ACTIVE', $offer['status']);
        self::assertSame('Маша Петрова', $offer['client']['name']);
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM schedule_allocations WHERE allocation_type = 'OFFER_RESERVATION' AND released_at IS NULL"));
        $outbound = $this->entityManager->getRepository(OutboundMessage::class)->findOneBy([]);
        self::assertInstanceOf(OutboundMessage::class, $outbound);
        self::assertStringStartsWith('free-window-offer-accept:', $outbound->buttons()[0][0]['action']);
        self::assertStringStartsWith('free-window-offer-decline:', $outbound->buttons()[1][0]['action']);
        $this->browser->jsonRequest('POST', '/api/availability/check', [
            'specialistId' => $this->specialist->id()->toRfc4122(),
            'startsAt' => $this->window->startsAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
            'endsAt' => $this->window->endsAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('TIME_ALREADY_UNAVAILABLE', $this->json()['conflict']['code']);

        $this->browser->request('GET', '/api/free-windows');
        self::assertSame($offer['id'], $this->json()[0]['activeOffer']['id']);
        $this->browser->jsonRequest('POST', '/api/free-windows/'.$this->window->id()->toRfc4122().'/offers', [
            'targetType' => 'WAITING_LIST', 'candidateId' => $entry['id'],
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseStatusCodeSame(409);

        self::getContainer()->get(FreeWindowOfferResponseConsumer::class)->consume($this->event($outbound->buttons()[0][0]['action'], $this->waitingChannel->id(), 'accept-waiting'));
        $this->entityManager->clear();
        $stored = $this->entityManager->find(FreeWindowOffer::class, $offer['id']);
        self::assertSame('ACCEPTED', $stored?->status()->value);
        self::assertNotNull($stored?->resultingAppointmentId());
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM schedule_allocations WHERE allocation_type = 'APPOINTMENT' AND released_at IS NULL AND starts_at = ?", [$this->windowStart->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::RFC3339_EXTENDED)]));
        self::assertSame('CLOSED', $this->entityManager->getConnection()->fetchOne('SELECT status FROM free_windows WHERE id = ?', [$this->window->id()->toRfc4122()]));
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM waiting_list_entries WHERE id = ? AND active = TRUE', [$entry['id']]));
    }

    public function testMoveEarlierOfferCreatesReplacementAndNewFreeWindow(): void
    {
        $offer = $this->offer('MOVE_EARLIER', $this->laterAppointment->id()->toRfc4122());
        self::assertResponseStatusCodeSame(201);
        $outbound = $this->entityManager->getRepository(OutboundMessage::class)->findOneBy([]);
        self::assertInstanceOf(OutboundMessage::class, $outbound);

        self::getContainer()->get(FreeWindowOfferResponseConsumer::class)->consume($this->event($outbound->buttons()[0][0]['action'], $this->laterChannel->id(), 'accept-earlier'));
        $this->entityManager->clear();
        $stored = $this->entityManager->find(FreeWindowOffer::class, $offer['id']);
        $source = $this->entityManager->find(Appointment::class, $this->laterAppointment->id());
        self::assertSame('ACCEPTED', $stored?->status()->value);
        self::assertSame('RESCHEDULED', $source?->resultStatus()?->value);
        self::assertSame($this->windowStart->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::RFC3339), $this->entityManager->find(Appointment::class, $stored?->resultingAppointmentId())?->startsAt()->format(\DateTimeInterface::RFC3339));
        self::assertSame(2, $this->countRows('free_windows'));
        self::assertSame($this->laterAppointment->id()->toRfc4122(), $this->entityManager->getConnection()->fetchOne("SELECT source_appointment_id FROM free_windows WHERE status = 'OPEN'"));
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM schedule_allocations WHERE released_at IS NULL'));
    }

    public function testDeclineAndAdministratorCancellationReleaseReservation(): void
    {
        $entry = $this->saveWaiting($this->waitingClient, true, [[
            'weekday' => (int) $this->windowStart->format('N'), 'startTime' => '15:00', 'endTime' => '19:00',
        ]]);
        $offer = $this->offer('WAITING_LIST', $entry['id']);
        $outbound = $this->entityManager->getRepository(OutboundMessage::class)->findOneBy([]);
        self::assertInstanceOf(OutboundMessage::class, $outbound);
        $consumer = self::getContainer()->get(FreeWindowOfferResponseConsumer::class);
        $consumer->consume($this->event($outbound->buttons()[1][0]['action'], $this->waitingChannel->id(), 'decline-offer'));
        $consumer->consume($this->event($outbound->buttons()[1][0]['action'], $this->waitingChannel->id(), 'repeat-decline'));
        self::assertSame('DECLINED', $this->entityManager->getConnection()->fetchOne('SELECT status FROM free_window_offers WHERE id = ?', [$offer['id']]));
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM schedule_allocations WHERE allocation_type = 'OFFER_RESERVATION' AND released_at IS NULL"));

        $second = $this->offer('WAITING_LIST', $entry['id']);
        self::assertResponseStatusCodeSame(201);
        $this->browser->request('DELETE', '/api/free-window-offers/'.$second['id'], server: ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('CANCELLED', $this->entityManager->getConnection()->fetchOne('SELECT status FROM free_window_offers WHERE id = ?', [$second['id']]));
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM schedule_allocations WHERE allocation_type = 'OFFER_RESERVATION' AND released_at IS NULL"));
        self::assertSame(3, $this->countRows('communication_outbox'));
    }

    public function testWrongChannelAndForeignTenantCannotActOnOffer(): void
    {
        $entry = $this->saveWaiting($this->waitingClient, true, [[
            'weekday' => (int) $this->windowStart->format('N'), 'startTime' => '15:00', 'endTime' => '19:00',
        ]]);
        $offer = $this->offer('WAITING_LIST', $entry['id']);
        $outbound = $this->entityManager->getRepository(OutboundMessage::class)->findOneBy([]);
        self::assertInstanceOf(OutboundMessage::class, $outbound);
        self::getContainer()->get(FreeWindowOfferResponseConsumer::class)->consume($this->event($outbound->buttons()[0][0]['action'], $this->laterChannel->id(), 'wrong-channel'));
        self::assertSame('ACTIVE', $this->entityManager->getConnection()->fetchOne('SELECT status FROM free_window_offers WHERE id = ?', [$offer['id']]));

        $foreign = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Foreign offer', 'foreign-offer-'.bin2hex(random_bytes(3)).'@example.test', 'test-password', 'UTC');
        $this->browser->loginUser($foreign);
        $this->browser->request('GET', '/api/auth/csrf');
        $foreignCsrf = $this->json()['mutationToken'];
        $this->browser->request('DELETE', '/api/free-window-offers/'.$offer['id'], server: ['HTTP_X_CSRF_TOKEN' => $foreignCsrf]);
        self::assertResponseStatusCodeSame(404);
        $this->browser->jsonRequest('POST', '/api/free-windows/'.$this->window->id()->toRfc4122().'/offers', [
            'targetType' => 'WAITING_LIST', 'candidateId' => $entry['id'],
        ], ['HTTP_X_CSRF_TOKEN' => $foreignCsrf]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAcceptanceClosesOfferWhenSpecialistBecameUnavailable(): void
    {
        $entry = $this->saveWaiting($this->waitingClient, true, [[
            'weekday' => (int) $this->windowStart->format('N'), 'startTime' => '15:00', 'endTime' => '19:00',
        ]]);
        $offer = $this->offer('WAITING_LIST', $entry['id']);
        $outbound = $this->entityManager->getRepository(OutboundMessage::class)->findOneBy([]);
        self::assertInstanceOf(OutboundMessage::class, $outbound);
        $specialist = $this->entityManager->find(Specialist::class, $this->specialist->id());
        self::assertInstanceOf(Specialist::class, $specialist);
        $specialist->setWeeklyHours(SpecialistWeeklyHours::emptyWeek());
        $this->entityManager->flush();

        self::getContainer()->get(FreeWindowOfferResponseConsumer::class)->consume($this->event($outbound->buttons()[0][0]['action'], $this->waitingChannel->id(), 'unavailable-offer'));
        self::assertSame('CANCELLED', $this->entityManager->getConnection()->fetchOne('SELECT status FROM free_window_offers WHERE id = ?', [$offer['id']]));
        self::assertSame('CLOSED', $this->entityManager->getConnection()->fetchOne('SELECT status FROM free_windows WHERE id = ?', [$this->window->id()->toRfc4122()]));
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM schedule_allocations WHERE allocation_type = 'OFFER_RESERVATION' AND released_at IS NULL"));
        self::assertSame(2, $this->countRows('communication_outbox'));
    }

    /** @param list<array{weekday: int, startTime: string, endTime: ?string}> $availability */
    private function saveWaiting(Client $client, bool $readyForOneOff, array $availability): array
    {
        $this->browser->jsonRequest('PUT', '/api/clients/'.$client->id()->toRfc4122().'/waiting-list', [
            'serviceId' => $this->service->id()->toRfc4122(),
            'specialistId' => null,
            'requiredFrequency' => 2,
            'readyForOneOff' => $readyForOneOff,
            'comment' => 'Могут приехать быстро',
            'availability' => $availability,
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);

        return $this->json();
    }

    private function appointment(Client $client, \DateTimeImmutable $startsAt): Appointment
    {
        return Appointment::create(
            $this->administrator->organization(), $this->specialist->id(), $client->id(), $this->service->id(), $this->service->name(),
            $this->service->defaultDurationMinutes(), null, null, 45, $startsAt,
        );
    }

    private function offer(string $targetType, string $candidateId): array
    {
        $this->browser->jsonRequest('POST', '/api/free-windows/'.$this->window->id()->toRfc4122().'/offers', [
            'targetType' => $targetType,
            'candidateId' => $candidateId,
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);

        return $this->json();
    }

    private function event(string $action, Ulid $channelId, string $id): NormalizedWebhookEvent
    {
        return NormalizedWebhookEvent::create($this->administrator->organization(), $channelId, CommunicationProvider::MAX, $id, ['kind' => 'BUTTON', 'action' => $action]);
    }

    private function countRows(string $table): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function json(): mixed
    {
        return json_decode((string) $this->browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
