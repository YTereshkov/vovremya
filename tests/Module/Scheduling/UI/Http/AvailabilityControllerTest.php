<?php

declare(strict_types=1);

namespace App\Tests\Module\Scheduling\UI\Http;

use App\Module\Identity\Application\CreateAdministrator\CreateAdministratorHandler;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Scheduling\Domain\Model\ScheduleAllocation;
use App\Module\Workforce\Domain\Model\AdditionalWorkingDay;
use App\Module\Workforce\Domain\Model\Specialist;
use App\Module\Workforce\Domain\Model\SpecialistWeeklyHours;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Ulid;

final class AvailabilityControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AdministratorAccount $administrator;
    private Specialist $specialist;
    private Specialist $foreignSpecialist;
    private string $csrf;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $handler = self::getContainer()->get(CreateAdministratorHandler::class);
        $this->administrator = $handler->create('Availability A', 'availability-a@example.test', 'test-password', 'Europe/Moscow');
        $foreignAdministrator = $handler->create('Availability B', 'availability-b@example.test', 'test-password', 'Europe/Moscow');

        $week = SpecialistWeeklyHours::emptyWeek();
        $week[0] = [
            'weekday' => 1,
            'enabled' => true,
            'work' => ['start' => '09:00', 'end' => '18:00'],
            'lunch' => ['start' => '13:00', 'end' => '14:00'],
        ];
        $this->specialist = new Specialist(new Ulid(), $this->administrator->organization(), 'Юлия', 'Логопед');
        $this->specialist->setWeeklyHours($week);
        $this->foreignSpecialist = new Specialist(new Ulid(), $foreignAdministrator->organization(), 'Анна', 'Психолог');
        $additionalDay = new AdditionalWorkingDay(
            new Ulid(),
            $this->specialist,
            '2026-09-13',
            ['start' => '10:00', 'end' => '14:00'],
        );
        $this->entityManager->persist($this->specialist);
        $this->entityManager->persist($this->foreignSpecialist);
        $this->entityManager->persist($additionalDay);
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

    public function testWorkingBoundariesAndLunchAreHardAvailable(): void
    {
        $fullDay = $this->check($this->specialist, '2026-09-07T09:00:00+03:00', '2026-09-07T18:00:00+03:00');
        self::assertResponseIsSuccessful();
        self::assertTrue($fullDay['available']);

        $lunch = $this->check($this->specialist, '2026-09-07T13:00:00+03:00', '2026-09-07T13:30:00+03:00');
        self::assertResponseIsSuccessful();
        self::assertTrue($lunch['available']);

        $outside = $this->check($this->specialist, '2026-09-07T08:59:00+03:00', '2026-09-07T10:00:00+03:00');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('SPECIALIST_NOT_WORKING', $outside['conflict']['code']);
    }

    public function testDayOffAndAdditionalWorkingDay(): void
    {
        $dayOff = $this->check($this->specialist, '2026-09-08T10:00:00+03:00', '2026-09-08T11:00:00+03:00');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('SPECIALIST_NOT_WORKING', $dayOff['conflict']['code']);

        $additional = $this->check($this->specialist, '2026-09-13T10:00:00+03:00', '2026-09-13T14:00:00+03:00');
        self::assertResponseIsSuccessful();
        self::assertTrue($additional['available']);
    }

    public function testActiveAllocationConflictsButAdjacentIntervalDoesNot(): void
    {
        $allocation = ScheduleAllocation::forAppointment(
            $this->administrator->organization(),
            $this->specialist->id(),
            new Ulid(),
            new \DateTimeImmutable('2026-09-07T10:00:00+03:00'),
            new \DateTimeImmutable('2026-09-07T11:00:00+03:00'),
        );
        $this->entityManager->persist($allocation);
        $this->entityManager->flush();

        $conflict = $this->check($this->specialist, '2026-09-07T10:30:00+03:00', '2026-09-07T11:30:00+03:00');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('TIME_ALREADY_UNAVAILABLE', $conflict['conflict']['code']);

        $adjacent = $this->check($this->specialist, '2026-09-07T11:00:00+03:00', '2026-09-07T12:00:00+03:00');
        self::assertResponseIsSuccessful();
        self::assertTrue($adjacent['available']);
    }

    public function testValidationCsrfAndTenantIsolation(): void
    {
        $this->client->jsonRequest('POST', '/api/availability/check', []);
        self::assertResponseStatusCodeSame(403);

        $this->client->jsonRequest('POST', '/api/availability/check', [
            'specialistId' => $this->specialist->id()->toRfc4122(),
            'startsAt' => '2026-09-07T10:00:00',
            'endsAt' => '2026-09-07T11:00:00',
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseStatusCodeSame(422);

        $this->check($this->specialist, '2026-09-07T11:00:00+03:00', '2026-09-07T10:00:00+03:00');
        self::assertResponseStatusCodeSame(422);

        $this->check($this->foreignSpecialist, '2026-09-07T10:00:00+03:00', '2026-09-07T11:00:00+03:00');
        self::assertResponseStatusCodeSame(404);

        $this->client->jsonRequest('POST', '/api/availability/check', [
            'specialistId' => $this->specialist->id()->toRfc4122(),
            'startsAt' => '2026-09-07T10:00:00+03:00',
            'endsAt' => '2026-09-07T11:00:00+03:00',
            'organizationId' => $this->foreignSpecialist->organizationId()->toRfc4122(),
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseStatusCodeSame(422);
    }

    /** @return array<string, mixed> */
    private function check(Specialist $specialist, string $startsAt, string $endsAt): array
    {
        $this->client->jsonRequest('POST', '/api/availability/check', [
            'specialistId' => $specialist->id()->toRfc4122(),
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);

        return $this->json();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
