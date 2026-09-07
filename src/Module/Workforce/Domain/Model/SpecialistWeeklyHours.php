<?php

declare(strict_types=1);

namespace App\Module\Workforce\Domain\Model;

final class SpecialistWeeklyHours
{
    /** @return list<array{weekday: int, enabled: bool, work: ?array, lunch: ?array}> */
    public static function validate(array $days): array
    {
        if (!array_is_list($days) || 7 !== count($days)) {
            throw new \InvalidArgumentException('Укажите все семь дней недели.');
        }
        $week = [];
        foreach ($days as $day) {
            if (!is_array($day) || !is_int($day['weekday'] ?? null) || $day['weekday'] < 1 || $day['weekday'] > 7
                || isset($week[$day['weekday']]) || !is_bool($day['enabled'] ?? null)) {
                throw new \InvalidArgumentException('Дни недели должны быть уникальными: от 1 до 7.');
            }
            $work = $day['enabled'] ? WorkInterval::validate($day['work'] ?? null) : null;
            $lunch = null !== $work ? WorkInterval::lunch($day['lunch'] ?? null, $work) : null;
            $week[$day['weekday']] = ['weekday' => $day['weekday'], 'enabled' => $day['enabled'], 'work' => $work, 'lunch' => $lunch];
        }
        ksort($week);

        return array_values($week);
    }

    public static function emptyWeek(): array
    {
        return array_map(static fn (int $day): array => ['weekday' => $day, 'enabled' => false, 'work' => null, 'lunch' => null], range(1, 7));
    }
}
