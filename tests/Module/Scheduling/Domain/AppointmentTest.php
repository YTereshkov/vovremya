<?php

declare(strict_types=1);

namespace App\Tests\Module\Scheduling\Domain;

use App\Module\Catalog\Domain\Model\Service;
use App\Module\Clients\Domain\Model\Client;
use App\Module\Organization\Domain\Model\Organization;
use App\Module\Scheduling\Domain\Model\Appointment;
use App\Module\Scheduling\Domain\Model\AppointmentResultStatus;
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

    #[Test]
    public function itRecordsIndependentResultAndCalculatesLateClientCancellation(): void
    {
        $organization = Organization::create('Кабинет', 'Europe/Moscow');
        $service = Service::create($organization, 'Занятие', 60, null, null);
        $appointment = $this->appointment(
            $organization,
            new Specialist(new Ulid(), $organization, 'Юлия', 'Логопед'),
            Client::create($organization, 'Петя', 'CHILD', null, null),
            $service,
            60,
        );

        $appointment->recordResult(
            AppointmentResultStatus::CancelledByClient,
            new \DateTimeImmutable('2026-09-06T22:01:00+00:00'),
            12,
            true,
            '  Болезнь  ',
        );

        self::assertSame(AppointmentResultStatus::CancelledByClient, $appointment->resultStatus());
        self::assertTrue($appointment->resultIsLate());
        self::assertTrue($appointment->resultRespectfulReason());
        self::assertSame('Болезнь', $appointment->resultComment());

        $recordedAt = $appointment->resultRecordedAt();
        $appointment->recordResult(AppointmentResultStatus::CancelledByClient, new \DateTimeImmutable('2026-09-07T09:00:00+00:00'), 48, true, 'Новая запись');
        self::assertSame($recordedAt, $appointment->resultRecordedAt());
        self::assertTrue($appointment->resultIsLate());
    }

    #[Test]
    public function cancellationExactlyAtThresholdIsNotLate(): void
    {
        $organization = Organization::create('Кабинет', 'Europe/Moscow');
        $service = Service::create($organization, 'Занятие', 60, null, null);
        $appointment = $this->appointment(
            $organization,
            new Specialist(new Ulid(), $organization, 'Юлия', 'Логопед'),
            Client::create($organization, 'Петя', 'CHILD', null, null),
            $service,
            60,
        );

        $appointment->recordResult(AppointmentResultStatus::CancelledByClient, new \DateTimeImmutable('2026-09-06T22:00:00+00:00'), 12, false, null);

        self::assertFalse($appointment->resultIsLate());
        $recordedAt = $appointment->resultRecordedAt();
        $appointment->recordResult(AppointmentResultStatus::CancelledByClient, new \DateTimeImmutable('2026-09-07T09:00:00+00:00'), 48, false, 'Комментарий');
        self::assertSame($recordedAt, $appointment->resultRecordedAt());
        self::assertFalse($appointment->resultIsLate());
    }

    #[Test]
    public function itRejectsRespectfulReasonOutsideLateClientCancellation(): void
    {
        $organization = Organization::create('Кабинет', 'Europe/Moscow');
        $service = Service::create($organization, 'Занятие', 60, null, null);
        $appointment = $this->appointment(
            $organization,
            new Specialist(new Ulid(), $organization, 'Юлия', 'Логопед'),
            Client::create($organization, 'Петя', 'CHILD', null, null),
            $service,
            60,
        );

        $this->expectException(\InvalidArgumentException::class);
        $appointment->recordResult(AppointmentResultStatus::CancelledBySpecialist, new \DateTimeImmutable('2026-09-06T23:00:00+00:00'), 12, true, null);
    }

    #[Test]
    public function conductedAndNoShowCannotBeRecordedBeforeStart(): void
    {
        $organization = Organization::create('Кабинет', 'Europe/Moscow');
        $service = Service::create($organization, 'Занятие', 60, null, null);
        $appointment = $this->appointment(
            $organization,
            new Specialist(new Ulid(), $organization, 'Юлия', 'Логопед'),
            Client::create($organization, 'Петя', 'CHILD', null, null),
            $service,
            60,
        );

        $this->expectException(\DomainException::class);
        $appointment->recordResult(AppointmentResultStatus::NoShow, new \DateTimeImmutable('2026-09-07T09:59:59+00:00'), 12, false, null);
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
