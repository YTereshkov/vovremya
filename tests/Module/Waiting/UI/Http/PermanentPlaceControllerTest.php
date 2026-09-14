<?php

declare(strict_types=1);

namespace App\Tests\Module\Waiting\UI\Http;

use App\Module\Catalog\Domain\Model\Service;
use App\Module\Clients\Domain\Model\ChannelConnection;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Communications\Domain\Model\CommunicationProvider;
use App\Module\Communications\Domain\Model\NormalizedWebhookEvent;
use App\Module\Communications\Domain\Model\OutboundMessage;
use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Scheduling\Application\RegularScheduleCreator;
use App\Module\Scheduling\Application\RegularScheduleStore;
use App\Module\Scheduling\Domain\Model\RegularSchedule;
use App\Module\Waiting\Application\PermanentPlaceOfferResponseConsumer;
use App\Module\Waiting\Application\PermanentPlaceOfferService;
use App\Module\Waiting\Domain\Model\PermanentPlace;
use App\Module\Waiting\Domain\Model\PermanentPlaceOffer;
use App\Module\Workforce\Domain\Model\Specialist;
use App\Module\Workforce\Domain\Model\SpecialistWeeklyHours;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Ulid;

final class PermanentPlaceControllerTest extends WebTestCase
{
    private KernelBrowser $browser;
    private EntityManagerInterface $entityManager;
    private AdministratorAccount $administrator;
    private Specialist $specialist;
    private Service $service;
    private Client $sourceClient;
    private Client $waitingClient;
    private ChannelConnection $channel;
    private RegularSchedule $schedule;
    private \DateTimeImmutable $availableFrom;
    private array $rules;
    private string $csrf;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->administrator = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Permanent place', 'permanent-'.bin2hex(random_bytes(3)).'@example.test', 'test-password', 'Europe/Moscow');
        $week = SpecialistWeeklyHours::emptyWeek();
        foreach ($week as $index => $day) {
            $week[$index] = ['weekday' => $day['weekday'], 'enabled' => true, 'work' => ['start' => '09:00', 'end' => '20:00'], 'lunch' => null];
        }
        $this->specialist = new Specialist(new Ulid(), $this->administrator->organization(), 'Юлия', 'Логопед');
        $this->specialist->setWeeklyHours($week);
        $this->service = Service::create($this->administrator->organization(), 'Логопедическое занятие', 45, null, null);
        $this->sourceClient = Client::create($this->administrator->organization(), 'Источник расписания', 'CHILD', null, null);
        $this->waitingClient = Client::create($this->administrator->organization(), 'Маша Петрова', 'CHILD', null, null);
        foreach ([$this->specialist, $this->service, $this->sourceClient, $this->waitingClient] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->channel = ChannelConnection::create($this->waitingClient, null, 'MAX', 'permanent-'.new Ulid());
        $this->channel->activate();
        $this->entityManager->persist($this->channel);
        $this->entityManager->flush();
        $this->waitingClient->selectPrimaryChannel($this->channel);
        $this->entityManager->flush();

        $this->browser->loginUser($this->administrator);

        $timezone = new \DateTimeZone($this->administrator->organization()->timezone());
        $today = new \DateTimeImmutable('today', $timezone);
        $this->availableFrom = $today->modify('+1 day');
        $this->rules = [
            ['weekday' => (int) $this->availableFrom->format('N'), 'startTime' => '17:00', 'durationMinutes' => 45],
            ['weekday' => (int) $this->availableFrom->modify('+3 days')->format('N'), 'startTime' => '15:00', 'durationMinutes' => 45],
        ];
        $this->schedule = self::getContainer()->get(RegularScheduleCreator::class)->create(
            $this->administrator,
            $this->specialist->id()->toRfc4122(),
            $this->sourceClient->id()->toRfc4122(),
            $this->service->id()->toRfc4122(),
            $today->format('Y-m-d'),
            null,
            $this->rules,
        );
        $this->browser->request('GET', '/api/auth/csrf');
        $this->csrf = $this->json()['mutationToken'];
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function testEndingScheduleCreatesWholeBundleAndMatchesRemainingNeed(): void
    {
        $entry = $this->saveWaiting(2, $this->rules);
        $this->endSchedule();

        self::assertSame(1, $this->countRows('permanent_places'));
        self::assertSame(2, $this->countRows('permanent_place_slots'));
        $this->browser->request('GET', '/api/permanent-places');
        self::assertResponseIsSuccessful();
        $places = $this->json();
        self::assertCount(1, $places);
        self::assertSame('BUNDLE', $places[0]['type']);
        self::assertCount(2, $places[0]['slots']);
        self::assertSame($this->availableFrom->format('Y-m-d'), $places[0]['availableFrom']);

        $this->browser->request('GET', '/api/permanent-places/'.$places[0]['id'].'/candidates');
        self::assertResponseIsSuccessful();
        $candidates = $this->json();
        self::assertSame($entry['id'], $candidates[0]['waitingListEntryId']);
        self::assertSame(2, $candidates[0]['remainingFrequency']);

        $this->saveWaiting(1, $this->rules);
        $this->browser->request('GET', '/api/permanent-places/'.$places[0]['id'].'/candidates');
        self::assertSame([], $this->json());
    }

    public function testEndingOneDayCreatesSinglePlaceAndKeepsScheduleActive(): void
    {
        $days = self::getContainer()->get(RegularScheduleStore::class)->days($this->schedule->id());
        $this->browser->jsonRequest('POST', sprintf('/api/regular-schedules/%s/days/%s/end', $this->schedule->id()->toRfc4122(), $days[0]->id()->toRfc4122()), [
            'fromDate' => $this->availableFrom->format('Y-m-d'),
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['active']);
        self::assertCount(2, $this->json()['days']);

        $this->browser->request('GET', '/api/permanent-places');
        self::assertResponseIsSuccessful();
        self::assertSame('SINGLE', $this->json()[0]['type']);
        self::assertCount(1, $this->json()[0]['slots']);
    }

    public function testMatchingSubtractsExistingRegularFrequency(): void
    {
        $timezone = new \DateTimeZone($this->administrator->organization()->timezone());
        self::getContainer()->get(RegularScheduleCreator::class)->create(
            $this->administrator,
            $this->specialist->id()->toRfc4122(),
            $this->waitingClient->id()->toRfc4122(),
            $this->service->id()->toRfc4122(),
            (new \DateTimeImmutable('today', $timezone))->format('Y-m-d'),
            null,
            [['weekday' => (int) $this->availableFrom->modify('+1 day')->format('N'), 'startTime' => '11:00', 'durationMinutes' => 45]],
        );
        $entry = $this->saveWaiting(3, $this->rules);
        $place = $this->endSchedule();

        $this->browser->request('GET', '/api/permanent-places/'.$place['id'].'/candidates');
        self::assertResponseIsSuccessful();
        self::assertSame($entry['id'], $this->json()[0]['waitingListEntryId']);
        self::assertSame(1, $this->json()[0]['currentFrequency']);
        self::assertSame(2, $this->json()[0]['remainingFrequency']);

        $this->saveWaiting(2, $this->rules);
        $this->browser->request('GET', '/api/permanent-places/'.$place['id'].'/candidates');
        self::assertSame([], $this->json());
    }

    public function testOfferReservesBundleAndAcceptanceCreatesRegularSchedule(): void
    {
        $entry = $this->saveWaiting(2, $this->rules);
        $place = $this->endSchedule();
        $offer = $this->offer($place['id'], $entry['id']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('ACTIVE', $offer['status']);
        self::assertGreaterThan(2, $this->activeReservations());
        $reservationCount = $this->activeReservations();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        self::getContainer()->get(PermanentPlaceOfferService::class)->extendActive($now);
        self::assertSame($reservationCount, $this->activeReservations());
        self::getContainer()->get(PermanentPlaceOfferService::class)->extendActive($now->modify('+7 days'));
        $extendedReservationCount = $this->activeReservations();
        self::assertGreaterThan($reservationCount, $extendedReservationCount);
        self::getContainer()->get(PermanentPlaceOfferService::class)->extendActive($now->modify('+7 days'));
        self::assertSame($extendedReservationCount, $this->activeReservations());
        self::assertSame('ACTIVE', $this->entityManager->getConnection()->fetchOne('SELECT status FROM permanent_place_offers WHERE id = ?', [$offer['id']]));
        $outbound = $this->entityManager->getRepository(OutboundMessage::class)->findOneBy([]);
        self::assertInstanceOf(OutboundMessage::class, $outbound);
        self::assertStringStartsWith('permanent-place-offer-accept:', $outbound->buttons()[0][0]['action']);
        self::assertStringStartsWith('permanent-place-offer-decline:', $outbound->buttons()[1][0]['action']);

        $this->offer($place['id'], $entry['id']);
        self::assertResponseStatusCodeSame(409);
        self::getContainer()->get(PermanentPlaceOfferResponseConsumer::class)->consume($this->event($outbound->buttons()[0][0]['action'], $this->channel->id(), 'accept-permanent'));
        self::getContainer()->get(PermanentPlaceOfferResponseConsumer::class)->consume($this->event($outbound->buttons()[0][0]['action'], $this->channel->id(), 'repeat-permanent'));
        $this->entityManager->clear();

        $stored = $this->entityManager->find(PermanentPlaceOffer::class, $offer['id']);
        self::assertSame('ACCEPTED', $stored?->status()->value);
        self::assertSame('CLAIMED', $this->entityManager->find(PermanentPlace::class, $place['id'])?->status()->value);
        self::assertSame(0, $this->activeReservations());
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM regular_schedules WHERE client_id = ? AND inactive_from IS NULL', [$this->waitingClient->id()->toRfc4122()]));
        self::assertSame(2, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM regular_schedule_days day INNER JOIN regular_schedules schedule ON schedule.id = day.regular_schedule_id WHERE schedule.client_id = ? AND day.inactive_from IS NULL', [$this->waitingClient->id()->toRfc4122()]));
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM waiting_list_entries WHERE id = ? AND active = TRUE', [$entry['id']]));
    }

    public function testDeclineCancellationAndTenantIsolationReleaseReservation(): void
    {
        $entry = $this->saveWaiting(2, $this->rules);
        $place = $this->endSchedule();
        $offer = $this->offer($place['id'], $entry['id']);
        $outbound = $this->entityManager->getRepository(OutboundMessage::class)->findOneBy([]);
        self::assertInstanceOf(OutboundMessage::class, $outbound);

        self::getContainer()->get(PermanentPlaceOfferResponseConsumer::class)->consume($this->event($outbound->buttons()[0][0]['action'], new Ulid(), 'wrong-channel-permanent'));
        self::assertSame('ACTIVE', $this->entityManager->getConnection()->fetchOne('SELECT status FROM permanent_place_offers WHERE id = ?', [$offer['id']]));
        self::getContainer()->get(PermanentPlaceOfferResponseConsumer::class)->consume($this->event($outbound->buttons()[1][0]['action'], $this->channel->id(), 'decline-permanent'));
        self::assertSame('DECLINED', $this->entityManager->getConnection()->fetchOne('SELECT status FROM permanent_place_offers WHERE id = ?', [$offer['id']]));
        self::assertSame(0, $this->activeReservations());

        $second = $this->offer($place['id'], $entry['id']);
        self::assertResponseStatusCodeSame(201);
        $foreign = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Foreign permanent', 'foreign-permanent-'.bin2hex(random_bytes(3)).'@example.test', 'test-password', 'UTC');
        $this->browser->loginUser($foreign);
        $this->browser->request('GET', '/api/auth/csrf');
        $foreignCsrf = $this->json()['mutationToken'];
        $this->browser->request('DELETE', '/api/permanent-place-offers/'.$second['id'], server: ['HTTP_X_CSRF_TOKEN' => $foreignCsrf]);
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('GET', '/api/permanent-places/'.$place['id'].'/candidates');
        self::assertResponseStatusCodeSame(404);

        $this->browser->loginUser($this->administrator);
        $this->browser->request('DELETE', '/api/permanent-place-offers/'.$second['id'], server: ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->activeReservations());
    }

    public function testAcceptanceRechecksAvailabilityAndCancelsUnavailableOffer(): void
    {
        $entry = $this->saveWaiting(2, $this->rules);
        $place = $this->endSchedule();
        $offer = $this->offer($place['id'], $entry['id']);
        $outbound = $this->entityManager->getRepository(OutboundMessage::class)->findOneBy([]);
        self::assertInstanceOf(OutboundMessage::class, $outbound);
        self::assertGreaterThan(0, $this->activeReservations());

        $specialist = $this->entityManager->find(Specialist::class, $this->specialist->id());
        self::assertInstanceOf(Specialist::class, $specialist);
        $specialist->setWeeklyHours(SpecialistWeeklyHours::emptyWeek());
        $this->entityManager->flush();
        self::getContainer()->get(PermanentPlaceOfferResponseConsumer::class)->consume($this->event($outbound->buttons()[0][0]['action'], $this->channel->id(), 'unavailable-permanent'));
        $this->entityManager->clear();

        self::assertSame('CANCELLED', $this->entityManager->find(PermanentPlaceOffer::class, $offer['id'])?->status()->value);
        self::assertSame('OPEN', $this->entityManager->find(PermanentPlace::class, $place['id'])?->status()->value);
        self::assertSame(0, $this->activeReservations());
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM regular_schedules WHERE client_id = ? AND inactive_from IS NULL', [$this->waitingClient->id()->toRfc4122()]));
    }

    /** @param list<array{weekday: int, startTime: string, durationMinutes: int}> $rules */
    private function saveWaiting(int $frequency, array $rules): array
    {
        $this->browser->jsonRequest('PUT', '/api/clients/'.$this->waitingClient->id()->toRfc4122().'/waiting-list', [
            'serviceId' => $this->service->id()->toRfc4122(),
            'specialistId' => null,
            'requiredFrequency' => $frequency,
            'readyForOneOff' => true,
            'comment' => null,
            'availability' => array_map(static fn (array $rule): array => [
                'weekday' => $rule['weekday'],
                'startTime' => '09:00',
                'endTime' => '19:00',
            ], $rules),
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    private function endSchedule(): array
    {
        $this->browser->jsonRequest('POST', '/api/regular-schedules/'.$this->schedule->id()->toRfc4122().'/end', [
            'fromDate' => $this->availableFrom->format('Y-m-d'),
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseIsSuccessful();
        $this->browser->request('GET', '/api/permanent-places');
        self::assertResponseIsSuccessful();

        return $this->json()[0];
    }

    private function offer(string $placeId, string $entryId): array
    {
        $this->browser->jsonRequest('POST', '/api/permanent-places/'.$placeId.'/offers', ['waitingListEntryId' => $entryId], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);

        return $this->json();
    }

    private function event(string $action, Ulid $channelId, string $id): NormalizedWebhookEvent
    {
        return NormalizedWebhookEvent::create($this->administrator->organization(), $channelId, CommunicationProvider::MAX, $id, ['kind' => 'BUTTON', 'action' => $action]);
    }

    private function activeReservations(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM schedule_allocations WHERE allocation_type = 'OFFER_RESERVATION' AND released_at IS NULL");
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
