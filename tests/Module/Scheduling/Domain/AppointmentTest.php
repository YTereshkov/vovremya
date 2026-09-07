<?php

declare(strict_types=1);

namespace App\Tests\Module\Scheduling\Domain;

use App\Module\Catalog\Domain\Model\Service;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Organization\Domain\Model\Organization;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Workforce\Domain\Model\Specialist;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class AppointmentTest extends TestCase
{
    #[Test]
    public function itUsesTheServiceDefaultAndKeepsItsSnapshot(): void
    {
        $organization = Organization::create('Кабинет', 'Europe/Moscow');
        $specialist = new Specialist(new Ulid(), $organization, 'Юлия', 'Логопед');
        $client = Client::create($organization, 'Петя', 'CHILD', null, null);
        $service = Service::create($organization, 'Диагностика', 80, 60, 90);
        $appointment = $this->appointment($organization, $specialist, $client, $service, 80);

        $service->change('Новая диагностика', 75, 60, 90);
        $service->delete();

        self::assertSame('Диагностика', $appointment->serviceName());
        self::assertSame(80, $appointment->serviceDefaultDuration());
        self::assertSame(80, $appointment->durationMinutes());
        self::assertSame('2026-09-07T11:20:00+00:00', $appointment->endsAt()->format('Y-m-d\TH:i:sP'));
    }

    #[Test]
    public function itRejectsDurationOutsideTheServiceRange(): void
    {
        $organization = Organization::create('Кабинет', 'Europe/Moscow');
        $service = Service::create($organization, 'Диагностика', 80, 60, 90);

        $this->expectException(\InvalidArgumentException::class);
        $this->appointment(
            $organization,
            new Specialist(new Ulid(), $organization, 'Юлия', 'Логопед'),
            Client::create($organization, 'Петя', 'CHILD', null, null),
            $service,
            59,
        );
    }

    #[Test]
    public function itKeepsReferencedIdentifiers(): void
    {
        $organization = Organization::create('Кабинет A', 'Europe/Moscow');
        $specialist = new Specialist(new Ulid(), $organization, 'Юлия', 'Логопед');
        $client = Client::create($organization, 'Петя', 'CHILD', null, null);
        $service = Service::create($organization, 'Диагностика', 80, 60, 90);

        $appointment = $this->appointment($organization, $specialist, $client, $service, 80);

        self::assertTrue($specialist->id()->equals($appointment->specialistId()));
        self::assertTrue($client->id()->equals($appointment->clientId()));
        self::assertTrue($service->id()->equals($appointment->serviceId()));
    }

    private function appointment(Organization $organization, Specialist $specialist, Client $client, Service $service, int $duration): Appointment
    {
        return Appointment::create(
            $organization,
            $specialist->id(),
            $client->id(),
            $service->id(),
            $service->name(),
            $service->defaultDurationMinutes(),
            $service->minimumDurationMinutes(),
            $service->maximumDurationMinutes(),
            $duration,
            new \DateTimeImmutable('2026-09-07T13:00:00+03:00'),
        );
    }
}
