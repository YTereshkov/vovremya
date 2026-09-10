<?php

declare(strict_types=1);

namespace App\Tests\Module\Scheduling\UI\Http;

use App\Module\Catalog\Domain\Model\Service;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Application\OrganizationContext;
use App\Module\Scheduling\Application\ScheduleAllocationStore;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\ScheduleAllocation;
use App\Module\Scheduling\Domain\Model\RegularSchedule;
use App\Module\Scheduling\Domain\Model\RegularScheduleDay;
use App\Module\Workforce\Domain\Model\Specialist;
use App\Module\Workforce\Domain\Model\SpecialistWeeklyHours;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Ulid;

final class AppointmentLifecycleControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AdministratorAccount $administrator;
    private Specialist $specialist;
    private Client $patient;
    private Service $service;
    private Appointment $appointment;
    private string $csrf;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->administrator = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Lifecycle', 'lifecycle-'.bin2hex(random_bytes(3)).'@example.test', 'test-password', 'Europe/Moscow');
        $week = SpecialistWeeklyHours::emptyWeek();
        $weekday = (int) (new \DateTimeImmutable('+2 days', new \DateTimeZone('Europe/Moscow')))->format('N');
        $week[$weekday - 1] = ['weekday' => $weekday, 'enabled' => true, 'work' => ['start' => '09:00', 'end' => '18:00'], 'lunch' => null];
        $this->specialist = new Specialist(new Ulid(), $this->administrator->organization(), 'Юлия', 'Логопед');
        $this->specialist->setWeeklyHours($week);
        $this->patient = Client::create($this->administrator->organization(), 'Петя', 'CHILD', null, null);
        $this->service = Service::create($this->administrator->organization(), 'Занятие', 60, null, null);
        $this->appointment = Appointment::create(
            $this->administrator->organization(),
            $this->specialist->id(),
            $this->patient->id(),
            $this->service->id(),
            $this->service->name(),
            60,
            null,
            null,
            60,
            new \DateTimeImmutable('+2 days 15:00', new \DateTimeZone('Europe/Moscow')),
        );
        foreach ([$this->specialist, $this->patient, $this->service, $this->appointment] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->client->loginUser($this->administrator);
        self::getContainer()->get(ScheduleAllocationStore::class)->save(ScheduleAllocation::forAppointment(
            $this->administrator->organization(),
            $this->specialist->id(),
            $this->appointment->id(),
            $this->appointment->startsAt(),
            $this->appointment->endsAt(),
        ));
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

    public function testClientCancellationKeepsAppointmentReleasesAllocationAndCreatesOneWindow(): void
    {
        $result = $this->record('CANCELLED_BY_CLIENT', true, 'Болезнь');

        self::assertResponseIsSuccessful();
        self::assertSame('CANCELLED_BY_CLIENT', $result['status']);
        self::assertTrue($result['freeWindowCreated']);
        self::assertSame(1, $this->countRows('appointments'));
        self::assertSame(0, $this->activeAllocations());
        self::assertSame(1, $this->countRows('free_windows'));

        $this->record('CANCELLED_BY_CLIENT', true, 'Болезнь');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->countRows('free_windows'));
        self::assertSame(1, $this->countRows('appointment_events'));

        $this->client->request('GET', '/api/appointments/'.$this->appointment->id()->toRfc4122().'/result');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['freeWindowOpen']);

        $this->client->request('GET', '/api/free-windows');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->json());
    }

    public function testChangingResultClosesWindowAndHistoryPreservesBothChanges(): void
    {
        $this->record('CANCELLED_BY_CLIENT', true, null);
        $this->record('CANCELLED_BY_SPECIALIST', false, 'Кабинет закрыт');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/free-windows');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json());

        $this->client->request('GET', '/api/appointments/'.$this->appointment->id()->toRfc4122().'/history');
        self::assertResponseIsSuccessful();
        $history = $this->json();
        self::assertSame(['RESULT_CHANGED', 'RESULT_CHANGED'], array_column($history, 'type'));
        self::assertSame('CANCELLED_BY_CLIENT', $history[1]['payload']['from']);
        self::assertSame('CANCELLED_BY_SPECIALIST', $history[1]['payload']['to']);
    }

    public function testSpecialistCancellationCannotCreateFreeWindow(): void
    {
        $this->record('CANCELLED_BY_SPECIALIST', true, null);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->activeAllocations());
        self::assertSame(0, $this->countRows('appointment_events'));
    }

    public function testBookedWindowIsNotReturnedAsAvailable(): void
    {
        $this->record('CANCELLED_BY_CLIENT', true, null);
        self::getContainer()->get(ScheduleAllocationStore::class)->save(ScheduleAllocation::forAppointment(
            $this->administrator->organization(),
            $this->specialist->id(),
            new Ulid(),
            $this->appointment->startsAt(),
            $this->appointment->endsAt(),
        ));

        $this->client->request('GET', '/api/free-windows');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json());
    }

    public function testCancellingRegularOccurrenceDoesNotEndItsRule(): void
    {
        $date = new \DateTimeImmutable('+3 days', new \DateTimeZone('UTC'));
        $schedule = RegularSchedule::create($this->administrator->organization(), $this->specialist, $this->patient, $this->service, $date, null);
        $day = RegularScheduleDay::create($schedule, (int) $date->format('N'), '11:00', 60, $date);
        $regularAppointment = Appointment::fromRegularSchedule(
            $schedule,
            $day,
            $date,
            new \DateTimeImmutable($date->format('Y-m-d').' 11:00', new \DateTimeZone('Europe/Moscow')),
        );
        foreach ([$schedule, $day, $regularAppointment] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        self::getContainer()->get(ScheduleAllocationStore::class)->save(ScheduleAllocation::forAppointment(
            $this->administrator->organization(), $this->specialist->id(), $regularAppointment->id(), $regularAppointment->startsAt(), $regularAppointment->endsAt(),
        ));

        $this->client->jsonRequest('PUT', '/api/appointments/'.$regularAppointment->id()->toRfc4122().'/result', [
            'status' => 'CANCELLED_BY_CLIENT', 'respectfulReason' => false, 'comment' => null, 'createFreeWindow' => false,
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);

        self::assertResponseIsSuccessful();
        self::assertNull($schedule->inactiveFrom());
        self::assertTrue($schedule->isActiveOn($date->modify('+7 days')));
        self::assertTrue($schedule->id()->equals($regularAppointment->regularScheduleId()));
        self::assertSame('PLANNED', $regularAppointment->planningStatus()->value);
    }

    public function testForeignTenantCannotReadOrChangeResultAndSettingsAreTenantScoped(): void
    {
        $this->record('CANCELLED_BY_CLIENT', true, null);
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('PUT', '/api/scheduling/settings', ['lateCancellationHours' => 24], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseIsSuccessful();

        $foreign = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Foreign lifecycle', 'foreign-lifecycle-'.bin2hex(random_bytes(3)).'@example.test', 'test-password', 'UTC');
        $this->client->loginUser($foreign);
        $this->client->request('GET', '/api/auth/csrf');
        $foreignCsrf = $this->json()['mutationToken'];
        $this->client->request('GET', '/api/scheduling/settings');
        self::assertResponseIsSuccessful();
        self::assertSame(12, $this->json()['lateCancellationHours']);
        $this->client->request('GET', '/api/free-windows');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json());
        $this->client->jsonRequest('PUT', '/api/appointments/'.$this->appointment->id()->toRfc4122().'/result', [
            'status' => 'CANCELLED_BY_CLIENT', 'respectfulReason' => false, 'comment' => null, 'createFreeWindow' => false,
        ], ['HTTP_X_CSRF_TOKEN' => $foreignCsrf]);
        self::assertResponseStatusCodeSame(404);
    }

    /** @return array<string, mixed> */
    private function record(string $status, bool $createFreeWindow, ?string $comment): array
    {
        $this->client->jsonRequest('PUT', '/api/appointments/'.$this->appointment->id()->toRfc4122().'/result', [
            'status' => $status,
            'respectfulReason' => false,
            'comment' => $comment,
            'createFreeWindow' => $createFreeWindow,
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);

        return $this->json();
    }

    private function activeAllocations(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM schedule_allocations WHERE released_at IS NULL');
    }

    private function countRows(string $table): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
