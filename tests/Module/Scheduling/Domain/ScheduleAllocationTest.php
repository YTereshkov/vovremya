<?php

declare(strict_types=1);

namespace App\Tests\Module\Scheduling\Domain;

use App\Module\Organization\Domain\Model\Organization;
use App\Module\Scheduling\Domain\Model\ScheduleAllocation;
use App\Module\Scheduling\Domain\Model\ScheduleAllocationType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class ScheduleAllocationTest extends TestCase
{
    public function testAppointmentUsesHalfOpenUtcIntervalAndCanBeReleased(): void
    {
        $allocation = ScheduleAllocation::forAppointment(
            Organization::create('Test', 'Europe/Moscow'),
            new Ulid(),
            new Ulid(),
            new \DateTimeImmutable('2026-09-07T12:00:00+03:00'),
            new \DateTimeImmutable('2026-09-07T13:00:00+03:00'),
        );

        self::assertSame(ScheduleAllocationType::Appointment, $allocation->type());
        self::assertSame('09:00', $allocation->startsAt()->format('H:i'));
        self::assertSame('UTC', $allocation->startsAt()->getTimezone()->getName());
        self::assertNull($allocation->releasedAt());

        $allocation->release(new \DateTimeImmutable('2026-09-07T14:00:00+03:00'));
        $releasedAt = $allocation->releasedAt();
        $allocation->release(new \DateTimeImmutable('2026-09-08T14:00:00+03:00'));

        self::assertSame($releasedAt, $allocation->releasedAt());
    }

    public function testRejectsEmptyOrReversedInterval(): void
    {
        $instant = new \DateTimeImmutable('2026-09-07T12:00:00+03:00');

        $this->expectException(\InvalidArgumentException::class);
        ScheduleAllocation::forAppointment(
            Organization::create('Test', 'Europe/Moscow'),
            new Ulid(),
            new Ulid(),
            $instant,
            $instant,
        );
    }

    public function testOnlyAppointmentsAndOfferReservationsCanAllocateTime(): void
    {
        $organization = Organization::create('Test', 'Europe/Moscow');
        $offer = ScheduleAllocation::forOfferReservation(
            $organization,
            new Ulid(),
            new Ulid(),
            new \DateTimeImmutable('2026-09-07T12:00:00+03:00'),
            new \DateTimeImmutable('2026-09-07T13:00:00+03:00'),
        );

        self::assertSame(ScheduleAllocationType::OfferReservation, $offer->type());
        self::assertSame(
            [ScheduleAllocationType::Appointment, ScheduleAllocationType::OfferReservation],
            ScheduleAllocationType::cases(),
        );
    }
}
