<?php

declare(strict_types=1);

namespace App\Tests\Module\Scheduling\UI\Http;

use App\Module\Catalog\Domain\Model\Service;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Scheduling\Application\AppointmentStore;
use App\Module\Scheduling\Application\AvailabilityService;
use App\Module\Scheduling\Application\RegularScheduleMaterializer;
use App\Module\Scheduling\Application\RegularScheduleStore;
use App\Module\Scheduling\Application\ScheduleAllocationStore;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\RegularSchedule;
use App\Module\Scheduling\Domain\Model\RegularScheduleDay;
use App\Module\Scheduling\Domain\Model\ScheduleAllocation;
use App\Module\Workforce\Domain\Model\Specialist;
use App\Module\Workforce\Domain\Model\SpecialistWeeklyHours;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Ulid;

final class RegularScheduleControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AdministratorAccount $administrator;
    private Specialist $specialist;
    private Client $patient;
    private Service $service;
    private string $csrf;
    private \DateTimeImmutable $startsOn;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->administrator = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Regular schedules', 'regular-schedules@example.test', 'test-password', 'Europe/Moscow');

        $week = SpecialistWeeklyHours::emptyWeek();
        foreach ($week as $index => $day) {
            $week[$index] = ['weekday' => $day['weekday'], 'enabled' => true, 'work' => ['start' => '08:00', 'end' => '20:00'], 'lunch' => null];
        }
        $this->specialist = new Specialist(new Ulid(), $this->administrator->organization(), 'Юлия', 'Логопед');
        $this->specialist->setWeeklyHours($week);
        $this->patient = Client::create($this->administrator->organization(), 'Петя Сидоров', 'CHILD', null, null);
        $this->service = Service::create($this->administrator->organization(), 'Занятие', 45, 30, 60);
        $this->entityManager->persist($this->specialist);
        $this->entityManager->persist($this->patient);
        $this->entityManager->persist($this->service);
        $this->entityManager->flush();

        $this->startsOn = new \DateTimeImmutable('tomorrow', new \DateTimeZone('Europe/Moscow'));
        $this->client->loginUser($this->administrator);
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

    public function testCreatesDifferentWeekdayRulesAndMaterializesAppointments(): void
    {
        $secondDay = $this->startsOn->modify('+1 day');
        $result = $this->createSchedule([
            ['weekday' => (int) $this->startsOn->format('N'), 'startTime' => '10:00', 'durationMinutes' => 45],
            ['weekday' => (int) $secondDay->format('N'), 'startTime' => '15:30', 'durationMinutes' => 60],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Занятие', $result['service']['name']);
        self::assertCount(2, $result['days']);
        self::assertSame(['10:00', '15:30'], array_column($result['days'], 'startTime'));
        self::assertSame(1, $this->countRows('regular_schedules'));
        self::assertSame(2, $this->countRows('regular_schedule_days'));
        self::assertGreaterThanOrEqual(16, $this->countRows('appointments'));
        self::assertSame($this->countRows('appointments'), $this->countRows('schedule_allocations'));
        self::assertSame(0, $this->countRows('schedule_generation_issues'));

        $this->client->request('GET', sprintf(
            '/api/calendar?from=%s&to=%s',
            $this->startsOn->format('Y-m-d'),
            $secondDay->format('Y-m-d'),
        ));
        self::assertResponseIsSuccessful();
        self::assertSame(['10:00', '15:30'], array_column($this->json()['appointments'], 'startTime'));
    }

    #[DataProvider('horizonProvider')]
    public function testOccurrencesRespectConfiguredHorizon(int $horizonDays): void
    {
        $schedule = RegularSchedule::create($this->administrator->organization(), $this->specialist, $this->patient, $this->service, $this->startsOn, null);
        $days = [];
        for ($weekday = 1; $weekday <= 7; ++$weekday) {
            $days[] = RegularScheduleDay::create($schedule, $weekday, '10:00', 45, $this->startsOn);
        }

        $occurrences = $this->materializer($horizonDays)->occurrences($schedule, $days, $this->startsOn);

        self::assertCount($horizonDays + 1, $occurrences);
        self::assertSame($this->startsOn->format('Y-m-d'), $occurrences[0]->date->format('Y-m-d'));
        self::assertSame($this->startsOn->modify(sprintf('+%d days', $horizonDays))->format('Y-m-d'), $occurrences[array_key_last($occurrences)]->date->format('Y-m-d'));
    }

    /** @return iterable<string, array{int}> */
    public static function horizonProvider(): iterable
    {
        yield 'seven days' => [7];
        yield 'fourteen days' => [14];
        yield 'sixty days' => [60];
    }

    public function testMaterializationIsIdempotentAndOnlyExtendsStoredHorizon(): void
    {
        $schedule = RegularSchedule::create($this->administrator->organization(), $this->specialist, $this->patient, $this->service, $this->startsOn, null);
        $this->entityManager->persist($schedule);
        $days = [];
        for ($weekday = 1; $weekday <= 7; ++$weekday) {
            $days[] = RegularScheduleDay::create($schedule, $weekday, '10:00', 45, $this->startsOn);
            $this->entityManager->persist($days[array_key_last($days)]);
        }
        $this->entityManager->flush();

        $this->materializer(7)->materialize($schedule, $this->startsOn);
        self::assertSame(8, $this->countScheduleAppointments($schedule));

        $this->materializer(7)->materialize($schedule, $this->startsOn);
        self::assertSame(8, $this->countScheduleAppointments($schedule));

        $this->materializer(14)->materialize($schedule, $this->startsOn);
        self::assertSame(15, $this->countScheduleAppointments($schedule));

        $this->materializer(7)->materialize($schedule, $this->startsOn);
        self::assertSame(15, $this->countScheduleAppointments($schedule));
        self::assertSame(15, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM schedule_allocations WHERE source_id IN (SELECT id FROM appointments WHERE regular_schedule_id = :schedule)',
            ['schedule' => $schedule->id()->toRfc4122()],
        ));
    }

    public function testServiceSnapshotSurvivesLaterCatalogChanges(): void
    {
        $created = $this->createSchedule([
            ['weekday' => (int) $this->startsOn->format('N'), 'startTime' => '10:00', 'durationMinutes' => 45],
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->service->change('Новое название', 60, 45, 90);
        $this->entityManager->flush();
        $this->client->request('GET', '/api/regular-schedules/'.$created['id']);

        self::assertResponseIsSuccessful();
        self::assertSame('Занятие', $this->json()['service']['name']);
        self::assertSame(45, $this->json()['service']['defaultDurationMinutes']);
        self::assertSame(30, $this->json()['service']['minimumDurationMinutes']);
        self::assertSame(60, $this->json()['service']['maximumDurationMinutes']);
    }

    public function testDeletedServiceCannotBeUsedForNewSchedule(): void
    {
        $this->service->delete();
        $this->entityManager->flush();

        $this->createSchedule([
            ['weekday' => (int) $this->startsOn->format('N'), 'startTime' => '10:00', 'durationMinutes' => 45],
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('Услуга не найдена.', $this->json()['message']);
        self::assertSame(0, $this->countRows('regular_schedules'));
    }

    public function testRejectsDuplicateWeekdaysAndDurationOutsideServiceRange(): void
    {
        $weekday = (int) $this->startsOn->format('N');
        $this->createSchedule([
            ['weekday' => $weekday, 'startTime' => '10:00', 'durationMinutes' => 45],
            ['weekday' => $weekday, 'startTime' => '11:00', 'durationMinutes' => 45],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Один день недели нельзя добавить дважды.', $this->json()['message']);

        $this->createSchedule([
            ['weekday' => $weekday, 'startTime' => '10:00', 'durationMinutes' => 90],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Длительность занятия не входит в допустимый диапазон услуги.', $this->json()['message']);
        self::assertSame(0, $this->countRows('regular_schedules'));
    }

    public function testInitialHardConflictReportsConcreteDateAndCreatesNothing(): void
    {
        $startsAt = new \DateTimeImmutable($this->startsOn->format('Y-m-d').' 10:00', new \DateTimeZone('Europe/Moscow'));
        $appointment = Appointment::create(
            $this->administrator->organization(),
            $this->specialist->id(),
            $this->patient->id(),
            $this->service->id(),
            $this->service->name(),
            45,
            30,
            60,
            45,
            $startsAt,
        );
        $this->entityManager->persist($appointment);
        $this->entityManager->persist(ScheduleAllocation::forAppointment(
            $this->administrator->organization(),
            $this->specialist->id(),
            $appointment->id(),
            $appointment->startsAt(),
            $appointment->endsAt(),
        ));
        $this->entityManager->flush();

        $result = $this->createSchedule([
            ['weekday' => (int) $this->startsOn->format('N'), 'startTime' => '10:00', 'durationMinutes' => 45],
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('HARD_CONFLICT', $result['kind']);
        self::assertSame($this->startsOn->format('Y-m-d'), $result['conflicts'][0]['date']);
        self::assertSame('TIME_ALREADY_UNAVAILABLE', $result['conflicts'][0]['code']);
        self::assertSame(0, $this->countRows('regular_schedules'));
        self::assertSame(1, $this->countRows('appointments'));
    }

    public function testEndingDayRetainsHistoryAndReleasesFutureAllocations(): void
    {
        $created = $this->createSchedule([
            ['weekday' => (int) $this->startsOn->format('N'), 'startTime' => '10:00', 'durationMinutes' => 45],
        ]);
        self::assertResponseStatusCodeSame(201);
        $removedAppointmentId = (string) $this->entityManager->getConnection()->fetchOne(
            "SELECT id FROM appointments WHERE planning_status = 'PLANNED' ORDER BY starts_at LIMIT 1",
        );

        $this->client->jsonRequest('POST', sprintf('/api/regular-schedules/%s/days/%s/end', $created['id'], $created['days'][0]['id']), [
            'fromDate' => $this->startsOn->format('Y-m-d'),
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json()['days']);
        self::assertGreaterThan(0, (int) $this->entityManager->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM appointments WHERE planning_status = 'REMOVED_FROM_SCHEDULE'",
        ));
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM schedule_allocations WHERE released_at IS NULL',
        ));
        $this->client->request('GET', sprintf('/api/calendar?from=%s&to=%s', $this->startsOn->format('Y-m-d'), $this->startsOn->format('Y-m-d')));
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json()['appointments']);
        $this->client->request('GET', '/api/appointments/'.$removedAppointmentId);
        self::assertResponseIsSuccessful();
    }

    public function testChangingDayReplacesPlannedOccurrencesAndKeepsOldRecords(): void
    {
        $created = $this->createSchedule([
            ['weekday' => (int) $this->startsOn->format('N'), 'startTime' => '10:00', 'durationMinutes' => 45],
        ]);
        self::assertResponseStatusCodeSame(201);
        $oldCount = $this->countRows('appointments');

        $this->client->jsonRequest('PATCH', sprintf('/api/regular-schedules/%s/days/%s', $created['id'], $created['days'][0]['id']), [
            'fromDate' => $this->startsOn->format('Y-m-d'),
            'startTime' => '11:30',
            'durationMinutes' => 45,
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);

        self::assertResponseIsSuccessful();
        self::assertSame('11:30', $this->json()['days'][0]['startTime']);
        self::assertSame($oldCount, (int) $this->entityManager->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM appointments WHERE planning_status = 'PLANNED'",
        ));
        self::assertSame($oldCount, (int) $this->entityManager->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM appointments WHERE planning_status = 'REMOVED_FROM_SCHEDULE'",
        ));
        self::assertSame($oldCount, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM schedule_allocations WHERE released_at IS NULL',
        ));
        self::assertSame('08:30:00', (string) $this->entityManager->getConnection()->fetchOne(
            "SELECT starts_at::time(0) FROM appointments WHERE planning_status = 'PLANNED' ORDER BY starts_at LIMIT 1",
        ));
    }

    public function testMaterializationIssueCanBeRetriedAfterConflictIsReleased(): void
    {
        $schedule = RegularSchedule::create($this->administrator->organization(), $this->specialist, $this->patient, $this->service, $this->startsOn, null);
        $day = RegularScheduleDay::create($schedule, (int) $this->startsOn->format('N'), '10:00', 45, $this->startsOn);
        $startsAt = new \DateTimeImmutable($this->startsOn->format('Y-m-d').' 10:00', new \DateTimeZone('Europe/Moscow'));
        $blockingSource = new Ulid();
        $this->entityManager->persist($schedule);
        $this->entityManager->persist($day);
        $this->entityManager->persist(ScheduleAllocation::forAppointment(
            $this->administrator->organization(),
            $this->specialist->id(),
            $blockingSource,
            $startsAt,
            $startsAt->modify('+45 minutes'),
        ));
        $this->entityManager->flush();

        self::getContainer()->get(RegularScheduleMaterializer::class)->materialize($schedule, $this->startsOn);
        $issue = $this->entityManager->getConnection()->fetchAssociative(
            "SELECT id, status FROM schedule_generation_issues WHERE occurrence_date = :date AND status = 'OPEN'",
            ['date' => $this->startsOn->format('Y-m-d')],
        );
        self::assertIsArray($issue);

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE schedule_allocations SET released_at = NOW() WHERE source_id = :source',
            ['source' => $blockingSource->toRfc4122()],
        );
        $this->client->jsonRequest('POST', '/api/schedule-generation-issues/'.$issue['id'].'/retry', [], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);

        self::assertResponseIsSuccessful();
        self::assertSame('RESOLVED', $this->json()['status']);
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM appointments WHERE occurrence_date = :date',
            ['date' => $this->startsOn->format('Y-m-d')],
        ));
    }

    public function testRegularScheduleCannotBeReadFromAnotherOrganization(): void
    {
        $created = $this->createSchedule([
            ['weekday' => (int) $this->startsOn->format('N'), 'startTime' => '10:00', 'durationMinutes' => 45],
        ]);
        self::assertResponseStatusCodeSame(201);
        $foreign = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Foreign schedule tenant', 'foreign-regular-schedule@example.test', 'test-password', 'Europe/Moscow');

        $this->client->loginUser($foreign);
        $this->client->request('GET', '/api/regular-schedules/'.$created['id']);

        self::assertResponseStatusCodeSame(404);
    }

    /** @param list<array{weekday: int, startTime: string, durationMinutes: int}> $days
     *  @return array<string, mixed>
     */
    private function createSchedule(array $days): array
    {
        $this->client->jsonRequest('POST', '/api/regular-schedules', [
            'specialistId' => $this->specialist->id()->toRfc4122(),
            'clientId' => $this->patient->id()->toRfc4122(),
            'serviceId' => $this->service->id()->toRfc4122(),
            'startsOn' => $this->startsOn->format('Y-m-d'),
            'endsOn' => null,
            'days' => $days,
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);

        return $this->json();
    }

    private function countRows(string $table): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function countScheduleAppointments(RegularSchedule $schedule): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM appointments WHERE regular_schedule_id = :schedule',
            ['schedule' => $schedule->id()->toRfc4122()],
        );
    }

    private function materializer(int $horizonDays): RegularScheduleMaterializer
    {
        return new RegularScheduleMaterializer(
            self::getContainer()->get(RegularScheduleStore::class),
            self::getContainer()->get(AppointmentStore::class),
            self::getContainer()->get(ScheduleAllocationStore::class),
            self::getContainer()->get(AvailabilityService::class),
            $horizonDays,
        );
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
