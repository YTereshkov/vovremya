<?php

declare(strict_types=1);

namespace App\Tests\Module\Communications\Domain;

use App\Module\Communications\Domain\Model\ConfirmationSettings;
use App\Module\Organization\Domain\Model\Organization;
use PHPUnit\Framework\TestCase;

final class ConfirmationSettingsTest extends TestCase
{
    public function testDefaultsAndOvernightQuietHours(): void
    {
        $settings = ConfirmationSettings::defaults(Organization::create('Test', 'Europe/Moscow'));

        self::assertSame('14:00', $settings->requestTime());
        self::assertTrue($settings->isQuietAt(new \DateTimeImmutable('2026-09-10 23:00:00', new \DateTimeZone('Europe/Moscow'))));
        self::assertTrue($settings->isQuietAt(new \DateTimeImmutable('2026-09-10 06:59:00', new \DateTimeZone('Europe/Moscow'))));
        self::assertFalse($settings->isQuietAt(new \DateTimeImmutable('2026-09-10 07:00:00', new \DateTimeZone('Europe/Moscow'))));
    }

    public function testInvalidCutoffIsRejected(): void
    {
        $settings = ConfirmationSettings::defaults(Organization::create('Test', 'UTC'));

        $this->expectException(\InvalidArgumentException::class);
        $settings->change('16:00', '14:00', true, 120, '07:00', '21:00', '07:00');
    }
}
