<?php

declare(strict_types=1);

namespace App\Tests\Module\Scheduling\UI\Http;

use App\Module\Catalog\Domain\Model\Service;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Workforce\Domain\Model\Specialist;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Ulid;

final class CalendarControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AdministratorAccount $administrator;
    private Specialist $firstSpecialist;
    private Specialist $secondSpecialist;
    private Client $patient;
    private Service $service;
    private Appointment $firstAppointment;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->administrator = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Calendar', 'calendar@example.test', 'test-password', 'Europe/Moscow');
        $this->firstSpecialist = new Specialist(new Ulid(), $this->administrator->organization(), 'Анна Алексеева', 'Психолог');
        $this->secondSpecialist = new Specialist(new Ulid(), $this->administrator->organization(), 'Борис Иванов', 'Логопед');
        $this->patient = Client::create($this->administrator->organization(), 'Петя Сидоров', 'CHILD', null, null);
        $this->service = Service::create($this->administrator->organization(), 'Первичное занятие', 45, 30, 60);
        $this->firstAppointment = $this->appointment($this->secondSpecialist, '2026-09-07T10:00:00+03:00');
        $sameTime = $this->appointment($this->firstSpecialist, '2026-09-07T10:00:00+03:00');
        $later = $this->appointment($this->firstSpecialist, '2026-09-07T12:30:00+03:00');
        foreach ([$this->firstSpecialist, $this->secondSpecialist, $this->patient, $this->service, $this->firstAppointment, $sameTime, $later] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->service->change('Переименованная услуга', 50, 30, 60);
        $this->entityManager->flush();
        $this->client->loginUser($this->administrator);
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }
        $this->entityManager->clear();
        parent::tearDown();
    }

    public function testReturnsSortedTenantCalendarInOrganizationTimezoneWithServiceSnapshot(): void
    {
        $this->client->request('GET', '/api/calendar?from=2026-09-07&to=2026-09-07');

        self::assertResponseIsSuccessful();
        $result = $this->json();
        self::assertSame('Europe/Moscow', $result['timezone']);
        self::assertSame(['10:00', '10:00', '12:30'], array_column($result['appointments'], 'startTime'));
        self::assertSame(['Анна Алексеева', 'Борис Иванов', 'Анна Алексеева'], array_column(array_column($result['appointments'], 'specialist'), 'name'));
        self::assertSame('2026-09-07T10:00:00.000+03:00', $result['appointments'][0]['startsAt']);
        self::assertSame('Первичное занятие', $result['appointments'][0]['service']['name']);
        self::assertSame(45, $result['appointments'][0]['service']['defaultDurationMinutes']);
        self::assertSame(['NOT_REQUESTED', 'NOT_REQUESTED', 'NOT_REQUESTED'], array_column($result['appointments'], 'confirmationStatus'));
    }

    public function testFiltersBySpecialistAndRejectsInvalidRanges(): void
    {
        $this->client->request('GET', '/api/calendar?from=2026-09-07&to=2026-09-13&specialistId='.$this->secondSpecialist->id()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->json()['appointments']);

        $this->client->request('GET', '/api/calendar?from=2026-09-08&to=2026-09-07');
        self::assertResponseStatusCodeSame(422);

        $this->client->request('GET', '/api/calendar?from=2026-09-01&to=2026-10-02');
        self::assertResponseStatusCodeSame(422);
    }

    public function testReturnsAppointmentDetailsAndHidesForeignTenantData(): void
    {
        $this->client->request('GET', '/api/appointments/'.$this->firstAppointment->id()->toRfc4122());
        self::assertResponseIsSuccessful();
        $details = $this->json();
        self::assertSame('Петя Сидоров', $details['client']['name']);
        self::assertSame('Первичное занятие', $details['service']['name']);
        self::assertSame('10:00', $details['startTime']);

        $foreign = self::getContainer()->get(CreateAdministratorHandler::class)
            ->create('Foreign calendar', 'foreign-calendar@example.test', 'test-password', 'Europe/Moscow');
        $foreignSpecialist = new Specialist(new Ulid(), $foreign->organization(), 'Чужой специалист', 'Психолог');
        $foreignClient = Client::create($foreign->organization(), 'Чужой клиент', 'ADULT', null, null);
        $foreignService = Service::create($foreign->organization(), 'Чужая услуга', 45, null, null);
        $foreignAppointment = Appointment::create($foreign->organization(), $foreignSpecialist->id(), $foreignClient->id(), $foreignService->id(), $foreignService->name(), 45, null, null, 45, new \DateTimeImmutable('2026-09-07T11:00:00+03:00'));
        foreach ([$foreignSpecialist, $foreignClient, $foreignService, $foreignAppointment] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $this->client->request('GET', '/api/calendar?from=2026-09-07&to=2026-09-07');
        self::assertResponseIsSuccessful();
        self::assertCount(3, $this->json()['appointments']);

        $this->client->request('GET', '/api/appointments/'.$foreignAppointment->id()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
    }

    private function appointment(Specialist $specialist, string $startsAt): Appointment
    {
        return Appointment::create(
            $this->administrator->organization(),
            $specialist->id(),
            $this->patient->id(),
            $this->service->id(),
            $this->service->name(),
            $this->service->defaultDurationMinutes(),
            $this->service->minimumDurationMinutes(),
            $this->service->maximumDurationMinutes(),
            45,
            new \DateTimeImmutable($startsAt),
        );
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
