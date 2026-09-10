<?php

declare(strict_types=1);

namespace App\Tests\Module\Scheduling\UI\Http;

use App\Module\Catalog\Domain\Model\Service;
use App\Module\Clients\Domain\Model\ChannelConnection;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Scheduling\Application\ScheduleAllocationStore;
use App\Module\Scheduling\Application\RegularScheduleMaterializer;
use App\Module\Scheduling\Application\RegularScheduleStore;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\RegularSchedule;
use App\Module\Scheduling\Domain\Model\RegularScheduleDay;
use App\Module\Scheduling\Domain\Model\ScheduleAllocation;
use App\Module\Workforce\Domain\Model\Specialist;
use App\Module\Workforce\Domain\Model\SpecialistWeeklyHours;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Ulid;

final class AbsenceControllerTest extends WebTestCase
{
    private KernelBrowser $browser;
    private EntityManagerInterface $entityManager;
    private AdministratorAccount $administrator;
    private Specialist $specialist;
    private Client $client;
    private Service $service;
    private string $csrf;
    private \DateTimeImmutable $date;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->administrator = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Absences', 'absences-'.bin2hex(random_bytes(3)).'@example.test', 'test-password', 'Europe/Moscow');
        $week = SpecialistWeeklyHours::emptyWeek();
        foreach ($week as $index => $day) {
            $week[$index] = ['weekday' => $day['weekday'], 'enabled' => true, 'work' => ['start' => '08:00', 'end' => '20:00'], 'lunch' => null];
        }
        $this->specialist = new Specialist(new Ulid(), $this->administrator->organization(), 'Юлия', 'Логопед');
        $this->specialist->setWeeklyHours($week);
        $this->client = Client::create($this->administrator->organization(), 'Петя', 'CHILD', null, null);
        $this->service = Service::create($this->administrator->organization(), 'Занятие', 60, null, null);
        $this->entityManager->persist($this->specialist);
        $this->entityManager->persist($this->client);
        $this->entityManager->persist($this->service);
        $this->entityManager->flush();
        $this->date = new \DateTimeImmutable('+3 days', new \DateTimeZone('Europe/Moscow'));
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

    public function testSpecialistAbsenceCancelsAppointmentsAndBecomesHardConflict(): void
    {
        $appointment = $this->appointment($this->client, '10:00');
        $date = $this->date->format('Y-m-d');
        $schedule = RegularSchedule::create($this->administrator->organization(), $this->specialist, $this->client, $this->service, $this->date, null);
        $day = RegularScheduleDay::create($schedule, (int) $this->date->format('N'), '12:00', 60, $this->date);
        $this->entityManager->persist($schedule);
        $this->entityManager->persist($day);
        $this->entityManager->flush();

        $this->browser->request('GET', sprintf('/api/specialists/%s/absence-impact?startsOn=%s&endsOn=%s', $this->specialist->id()->toRfc4122(), $date, $date));
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->json()['appointments']);

        $this->browser->jsonRequest('POST', '/api/specialists/'.$this->specialist->id()->toRfc4122().'/absences', [
            'type' => 'VACATION',
            'startsOn' => $date,
            'endsOn' => $date,
            'comment' => 'Ежегодный отпуск',
            'notifyClients' => false,
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $this->json()['affectedAppointments']);
        self::assertSame('CANCELLED_BY_SPECIALIST', $this->resultStatus($appointment));
        self::assertSame(0, $this->activeAllocations($appointment));
        self::assertSame(0, $this->countRows('free_windows'));
        self::assertSame(['SPECIALIST_ABSENCE_CANCELLED'], $this->eventTypes($appointment));

        $this->browser->jsonRequest('POST', '/api/availability/check', [
            'specialistId' => $this->specialist->id()->toRfc4122(),
            'startsAt' => $this->date->format('Y-m-d').'T10:00:00+03:00',
            'endsAt' => $this->date->format('Y-m-d').'T11:00:00+03:00',
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('SPECIALIST_ABSENT', $this->json()['conflict']['code']);

        $scheduleId = $schedule->id();
        $this->entityManager->clear();
        $schedule = self::getContainer()->get(RegularScheduleStore::class)->find($scheduleId);
        self::assertInstanceOf(RegularSchedule::class, $schedule);
        self::getContainer()->get(RegularScheduleMaterializer::class)->materialize($schedule, $this->date);
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM schedule_generation_issues WHERE regular_schedule_id = :schedule AND occurrence_date = :date AND conflict_code = :code',
            ['schedule' => $schedule->id()->toRfc4122(), 'date' => $date, 'code' => 'SPECIALIST_ABSENT'],
        ));
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM appointments WHERE regular_schedule_id = :schedule AND occurrence_date = :date AND planning_status = 'PLANNED'",
            ['schedule' => $schedule->id()->toRfc4122(), 'date' => $date],
        ));
        self::assertNull($schedule->inactiveFrom());
    }

    public function testClientAbsenceKeepsRuleAndCreatesWindowsForAffectedAppointments(): void
    {
        $channel = ChannelConnection::create($this->client, null, 'MAX', 'absence-recipient');
        $channel->activate();
        $this->entityManager->persist($channel);
        $this->entityManager->flush();
        $this->client->selectPrimaryChannel($channel);
        $this->entityManager->flush();
        $oneOff = $this->appointment($this->client, '10:00');
        [$schedule, $regular] = $this->regularAppointment($this->client, '12:00');
        $date = $this->date->format('Y-m-d');

        $this->browser->jsonRequest('POST', '/api/clients/'.$this->client->id()->toRfc4122().'/absences', [
            'startsOn' => $date,
            'endsOn' => $date,
            'reason' => 'Отпуск',
            'mode' => 'KEEP_PERMANENT_PLACE',
            'createFreeWindows' => true,
            'notifyClient' => true,
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(2, $this->json()['affectedAppointments']);
        self::assertSame(2, $this->json()['freeWindowsCreated']);
        self::assertSame(1, $this->json()['queuedNotifications']);
        self::assertSame('CANCELLED_BY_CLIENT', $this->resultStatus($oneOff));
        self::assertSame('CANCELLED_BY_CLIENT', $this->resultStatus($regular));
        self::assertNull($this->entityManager->getConnection()->fetchOne('SELECT inactive_from FROM regular_schedules WHERE id = :id', ['id' => $schedule->id()->toRfc4122()]) ?: null);
        self::assertSame(2, $this->countRows('free_windows'));
        self::assertSame(1, $this->countRows('communication_outbox'));
    }

    public function testReleasingPermanentPlaceEndsRulesAndKeepsOneOffHistory(): void
    {
        $oneOff = $this->appointment($this->client, '10:00');
        [$schedule, $regular] = $this->regularAppointment($this->client, '12:00');
        $date = $this->date->format('Y-m-d');

        $this->browser->jsonRequest('POST', '/api/clients/'.$this->client->id()->toRfc4122().'/absences', [
            'startsOn' => $date,
            'endsOn' => $date,
            'reason' => null,
            'mode' => 'RELEASE_PERMANENT_PLACE',
            'createFreeWindows' => false,
            'notifyClient' => false,
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $this->json()['affectedAppointments']);
        self::assertSame(1, $this->json()['regularSchedulesEnded']);
        self::assertSame('CANCELLED_BY_CLIENT', $this->resultStatus($oneOff));
        self::assertSame('REMOVED_FROM_SCHEDULE', $this->planningStatus($regular));
        self::assertSame($date, $this->entityManager->getConnection()->fetchOne('SELECT inactive_from FROM regular_schedules WHERE id = :id', ['id' => $schedule->id()->toRfc4122()]));
        self::assertSame(0, $this->countRows('free_windows'));
    }

    public function testForeignTenantCannotCreateAbsences(): void
    {
        $foreign = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Foreign', 'foreign-absence-'.bin2hex(random_bytes(3)).'@example.test', 'test-password', 'UTC');
        $this->browser->loginUser($foreign);
        $this->browser->request('GET', '/api/auth/csrf');
        $csrf = $this->json()['mutationToken'];
        $date = $this->date->format('Y-m-d');

        $this->browser->jsonRequest('POST', '/api/specialists/'.$this->specialist->id()->toRfc4122().'/absences', [
            'type' => 'VACATION', 'startsOn' => $date, 'endsOn' => $date, 'comment' => null, 'notifyClients' => false,
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(404);

        $this->browser->jsonRequest('POST', '/api/clients/'.$this->client->id()->toRfc4122().'/absences', [
            'startsOn' => $date, 'endsOn' => $date, 'reason' => null, 'mode' => 'KEEP_PERMANENT_PLACE', 'createFreeWindows' => false, 'notifyClient' => false,
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->countRows('specialist_absences'));
        self::assertSame(0, $this->countRows('client_absences'));
    }

    private function appointment(Client $client, string $time): Appointment
    {
        $appointment = Appointment::create(
            $this->administrator->organization(), $this->specialist->id(), $client->id(), $this->service->id(),
            $this->service->name(), 60, null, null, 60,
            new \DateTimeImmutable($this->date->format('Y-m-d').' '.$time, new \DateTimeZone('Europe/Moscow')),
        );
        $this->entityManager->persist($appointment);
        $this->entityManager->flush();
        self::getContainer()->get(ScheduleAllocationStore::class)->save(ScheduleAllocation::forAppointment(
            $this->administrator->organization(), $this->specialist->id(), $appointment->id(), $appointment->startsAt(), $appointment->endsAt(),
        ));

        return $appointment;
    }

    /** @return array{RegularSchedule, Appointment} */
    private function regularAppointment(Client $client, string $time): array
    {
        $schedule = RegularSchedule::create($this->administrator->organization(), $this->specialist, $client, $this->service, $this->date, null);
        $day = RegularScheduleDay::create($schedule, (int) $this->date->format('N'), $time, 60, $this->date);
        $appointment = Appointment::fromRegularSchedule(
            $schedule, $day, $this->date,
            new \DateTimeImmutable($this->date->format('Y-m-d').' '.$time, new \DateTimeZone('Europe/Moscow')),
        );
        foreach ([$schedule, $day, $appointment] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        self::getContainer()->get(ScheduleAllocationStore::class)->save(ScheduleAllocation::forAppointment(
            $this->administrator->organization(), $this->specialist->id(), $appointment->id(), $appointment->startsAt(), $appointment->endsAt(),
        ));

        return [$schedule, $appointment];
    }

    private function resultStatus(Appointment $appointment): ?string
    {
        return $this->entityManager->getConnection()->fetchOne('SELECT result_status FROM appointments WHERE id = :id', ['id' => $appointment->id()->toRfc4122()]) ?: null;
    }

    private function planningStatus(Appointment $appointment): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne('SELECT planning_status FROM appointments WHERE id = :id', ['id' => $appointment->id()->toRfc4122()]);
    }

    private function activeAllocations(Appointment $appointment): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM schedule_allocations WHERE source_id = :id AND released_at IS NULL', ['id' => $appointment->id()->toRfc4122()]);
    }

    /** @return list<string> */
    private function eventTypes(Appointment $appointment): array
    {
        return $this->entityManager->getConnection()->fetchFirstColumn('SELECT event_type FROM appointment_events WHERE appointment_id = :id ORDER BY occurred_at, id', ['id' => $appointment->id()->toRfc4122()]);
    }

    private function countRows(string $table): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
