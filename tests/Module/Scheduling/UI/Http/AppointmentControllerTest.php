<?php

declare(strict_types=1);

namespace App\Tests\Module\Scheduling\UI\Http;

use App\Module\Catalog\Domain\Model\Service;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\AppointmentEvent;
use App\Module\Workforce\Domain\Model\Specialist;
use App\Module\Workforce\Domain\Model\SpecialistWeeklyHours;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Ulid;

final class AppointmentControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AdministratorAccount $administrator;
    private Specialist $specialist;
    private Client $patient;
    private Service $service;
    private string $csrf;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->administrator = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Appointments', 'appointments@example.test', 'test-password', 'Europe/Moscow');

        $week = SpecialistWeeklyHours::emptyWeek();
        $week[0] = ['weekday' => 1, 'enabled' => true, 'work' => ['start' => '09:00', 'end' => '18:00'], 'lunch' => ['start' => '13:00', 'end' => '14:00']];
        $this->specialist = new Specialist(new Ulid(), $this->administrator->organization(), 'Юлия', 'Логопед');
        $this->specialist->setWeeklyHours($week);
        $this->patient = Client::create($this->administrator->organization(), 'Петя Сидоров', 'CHILD', null, null);
        $this->service = Service::create($this->administrator->organization(), 'Диагностика', 80, 60, 90);
        $this->entityManager->persist($this->specialist);
        $this->entityManager->persist($this->patient);
        $this->entityManager->persist($this->service);
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
        $this->entityManager->clear();
        parent::tearDown();
    }

    public function testCreatesOneOffAppointmentWithDefaultDurationAndSnapshot(): void
    {
        $result = $this->createAppointment('10:00');

        self::assertResponseStatusCodeSame(201);
        self::assertSame(80, $result['durationMinutes']);
        self::assertSame('Диагностика', $result['service']['name']);
        self::assertSame('2026-09-07T07:00:00.000+00:00', $result['startsAt']);
        self::assertSame('2026-09-07T08:20:00.000+00:00', $result['endsAt']);
        self::assertSame(1, $this->countRows('appointments'));
        self::assertSame(1, $this->countRows('schedule_allocations'));

        $this->service->change('Диагностика речи', 75, 60, 90);
        $this->service->delete();
        $this->entityManager->flush();
        $appointment = $this->entityManager->getRepository(Appointment::class)->find($result['id']);
        self::assertInstanceOf(Appointment::class, $appointment);
        self::assertSame('Диагностика', $appointment->serviceName());
        self::assertSame(80, $appointment->serviceDefaultDuration());
    }

    public function testManualDurationMustStayInsideServiceRange(): void
    {
        $created = $this->createAppointment('10:00', 60);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(60, $created['durationMinutes']);

        $this->createAppointment('12:00', 59);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->countRows('appointments'));
    }

    public function testLunchWarningRequiresExplicitAcceptanceAndCreatesAuditEvent(): void
    {
        $warning = $this->createAppointment('13:00');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('SOFT_WARNING', $warning['kind']);
        self::assertSame('LUNCH_OVERLAP', $warning['warnings'][0]['code']);
        self::assertSame(0, $this->countRows('appointments'));

        $repeated = $this->createAppointment('13:00');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('SOFT_WARNING', $repeated['kind']);

        $created = $this->createAppointment('13:00', null, ['LUNCH_OVERLAP']);
        self::assertResponseStatusCodeSame(201);
        self::assertArrayHasKey('id', $created);
        self::assertSame(1, $this->countRows('appointments'));
        self::assertSame(1, $this->countRows('appointment_events'));
        $event = $this->entityManager->getRepository(AppointmentEvent::class)->findOneBy([]);
        self::assertInstanceOf(AppointmentEvent::class, $event);
        self::assertSame('SOFT_WARNINGS_ACCEPTED', $event->type());
        self::assertSame('LUNCH_OVERLAP', $event->payload()['warnings'][0]['code']);
    }

    public function testShortBreakWarnsButOverlapRemainsHardConflict(): void
    {
        $this->createAppointment('10:00', 60);
        self::assertResponseStatusCodeSame(201);

        $shortBreak = $this->createAppointment('11:10', 60);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('SOFT_WARNING', $shortBreak['kind']);
        self::assertSame('SHORT_BREAK', $shortBreak['warnings'][0]['code']);
        self::assertSame(10, $shortBreak['warnings'][0]['details']['minutes']);

        $this->createAppointment('11:10', 60, ['SHORT_BREAK']);
        self::assertResponseStatusCodeSame(201);

        $overlap = $this->createAppointment('10:30', 60, ['SHORT_BREAK']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('HARD_CONFLICT', $overlap['kind']);
        self::assertSame('TIME_ALREADY_UNAVAILABLE', $overlap['conflict']['code']);
    }

    public function testDeletedServiceAndForeignResourcesAreNotAvailable(): void
    {
        $deletedServiceId = $this->service->id()->toRfc4122();
        $this->service->delete();
        $this->service = Service::create($this->administrator->organization(), 'Занятие', 45, null, null);
        $this->entityManager->persist($this->service);
        $foreign = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Foreign', 'appointments-foreign@example.test', 'test-password', 'Europe/Moscow');
        $foreignClient = Client::create($foreign->organization(), 'Чужой клиент', 'ADULT', null, null);
        $this->entityManager->persist($foreignClient);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/api/appointments', array_merge($this->payload('10:00'), ['serviceId' => $deletedServiceId]), ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseStatusCodeSame(404);

        $this->client->jsonRequest('POST', '/api/appointments', array_merge($this->payload('10:00'), ['clientId' => $foreignClient->id()->toRfc4122()]), ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testInvalidPayloadAndCsrfAreRejected(): void
    {
        $this->client->jsonRequest('POST', '/api/appointments', $this->payload('10:00'));
        self::assertResponseStatusCodeSame(403);

        $this->client->jsonRequest('POST', '/api/appointments', $this->payload('10:00') + ['organizationId' => 'forbidden'], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseStatusCodeSame(422);
    }

    /** @param list<string> $acceptedWarnings
     *  @return array<string, mixed>
     */
    private function createAppointment(string $time, ?int $duration = null, array $acceptedWarnings = []): array
    {
        $payload = $this->payload($time);
        if (null !== $duration) {
            $payload['durationMinutes'] = $duration;
        }
        if ([] !== $acceptedWarnings) {
            $payload['acceptedWarnings'] = $acceptedWarnings;
        }
        $this->client->jsonRequest('POST', '/api/appointments', $payload, ['HTTP_X_CSRF_TOKEN' => $this->csrf]);

        return $this->json();
    }

    /** @return array<string, mixed> */
    private function payload(string $time): array
    {
        return [
            'specialistId' => $this->specialist->id()->toRfc4122(),
            'clientId' => $this->patient->id()->toRfc4122(),
            'serviceId' => $this->service->id()->toRfc4122(),
            'date' => '2026-09-07',
            'startTime' => $time,
        ];
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
