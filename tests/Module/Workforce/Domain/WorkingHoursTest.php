<?php

declare(strict_types=1);

namespace App\Tests\Module\Workforce\Domain;

use App\Module\Workforce\Domain\Model\SpecialistWeeklyHours;
use App\Module\Workforce\Domain\Model\WorkInterval;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkingHoursTest extends TestCase
{
    public function testWeekPreservesDifferentHoursAndLunchesAndClearsDisabledDays(): void
    {
        $week = SpecialistWeeklyHours::emptyWeek();
        $week[0] = ['weekday' => 1, 'enabled' => true, 'work' => ['start' => '09:00', 'end' => '18:00'], 'lunch' => ['start' => '13:00', 'end' => '14:00']];
        $week[1] = ['weekday' => 2, 'enabled' => true, 'work' => ['start' => '10:00', 'end' => '19:00'], 'lunch' => ['start' => '13:30', 'end' => '14:30']];
        $week[2] = ['weekday' => 3, 'enabled' => true, 'work' => ['start' => '10:00', 'end' => '18:00'], 'lunch' => null];
        $week[6]['lunch'] = ['start' => '12:00', 'end' => '13:00'];
        $validated = SpecialistWeeklyHours::validate(array_reverse($week));
        self::assertSame($week[0], $validated[0]);
        self::assertSame($week[1], $validated[1]);
        self::assertSame($week[2], $validated[2]);
        self::assertNull($validated[6]['work']);
        self::assertNull($validated[6]['lunch']);
    }

    #[DataProvider('invalidIntervals')]
    public function testRejectsInvalidIntervals(mixed $interval): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WorkInterval::validate($interval);
    }

    public static function invalidIntervals(): iterable
    {
        yield [null]; yield [['start' => '24:00', 'end' => '25:00']];
        yield [['start' => '09:60', 'end' => '18:00']]; yield [['start' => '18:00', 'end' => '09:00']];
        yield [['start' => '09:00', 'end' => '09:00']]; yield [['start' => 900, 'end' => '18:00']];
    }

    public function testRejectsLunchOutsideWork(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WorkInterval::lunch(['start' => '08:00', 'end' => '10:00'], ['start' => '09:00', 'end' => '18:00']);
    }

    public function testRejectsDuplicateWeekdays(): void
    {
        $week = SpecialistWeeklyHours::emptyWeek(); $week[6]['weekday'] = 1;
        $this->expectException(\InvalidArgumentException::class);
        SpecialistWeeklyHours::validate($week);
    }
}
