<?php

declare(strict_types=1);

namespace App\Tests\Module\Catalog\Domain;

use App\Module\Catalog\Domain\Model\Service;
use App\Module\Organization\Domain\Model\Organization;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServiceTest extends TestCase
{
    public function testDefaultDurationMustStayInsideRange(): void
    {
        $service = Service::create(Organization::create('Test', 'Europe/Moscow'), 'Диагностика', 60, 45, 90);

        self::assertSame(60, $service->defaultDurationMinutes());
        self::assertSame(45, $service->minimumDurationMinutes());
        self::assertSame(90, $service->maximumDurationMinutes());
    }

    #[DataProvider('invalidDurations')]
    public function testRejectsInvalidDurations(int $default, int $minimum, int $maximum): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Service::create(Organization::create('Test', 'Europe/Moscow'), 'Диагностика', $default, $minimum, $maximum);
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function invalidDurations(): iterable
    {
        yield 'default below minimum' => [30, 45, 90];
        yield 'default above maximum' => [120, 45, 90];
        yield 'zero minimum' => [30, 0, 90];
        yield 'more than one day' => [60, 30, 1441];
    }

    public function testDeleteIsIdempotentSoftDelete(): void
    {
        $service = Service::create(Organization::create('Test', 'Europe/Moscow'), 'Диагностика', 60, 45, 90);
        $service->delete();
        $deletedAt = $service->deletedAt();
        $service->delete();

        self::assertNotNull($deletedAt);
        self::assertSame($deletedAt, $service->deletedAt());
    }

    public function testDurationBoundsAreOptional(): void
    {
        $service = Service::create(Organization::create('Test', 'Europe/Moscow'), 'Консультация', 45, null, null);

        self::assertNull($service->minimumDurationMinutes());
        self::assertNull($service->maximumDurationMinutes());
    }
}
