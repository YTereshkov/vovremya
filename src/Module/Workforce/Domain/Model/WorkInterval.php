<?php

declare(strict_types=1);

namespace App\Module\Workforce\Domain\Model;

final class WorkInterval
{
    /** @return array{start: string, end: string} */
    public static function validate(mixed $value): array
    {
        if (!is_array($value) || !is_string($value['start'] ?? null) || !is_string($value['end'] ?? null)
            || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value['start'])
            || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value['end'])
            || $value['start'] >= $value['end']) {
            throw new \InvalidArgumentException('Укажите время начала и окончания в пределах одного дня. Начало должно быть раньше окончания.');
        }

        return ['start' => $value['start'], 'end' => $value['end']];
    }

    /** @return array{start: string, end: string}|null */
    public static function lunch(mixed $value, array $work): ?array
    {
        if (null === $value) {
            return null;
        }
        $lunch = self::validate($value);
        if ($lunch['start'] < $work['start'] || $lunch['end'] > $work['end']) {
            throw new \InvalidArgumentException('Обед должен находиться внутри рабочего времени.');
        }

        return $lunch;
    }
}
