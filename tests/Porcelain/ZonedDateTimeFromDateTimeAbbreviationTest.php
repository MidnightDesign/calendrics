<?php

declare(strict_types=1);

namespace Calendrics\Tests\Porcelain;

use Calendrics\ZonedDateTime;

final class ZonedDateTimeFromDateTimeAbbreviationTest extends CalendricsTestCase
{
    public function testZuluDesignatorBecomesUtc(): void
    {
        $zdt = ZonedDateTime::fromDateTime(new \DateTimeImmutable('2026-07-10T18:00:00Z'));

        static::assertSame('2026-07-10T18:00:00+00:00[UTC]', (string) $zdt);
    }

    public function testZeroOffsetAbbreviationBecomesUtc(): void
    {
        $zdt = ZonedDateTime::fromDateTime(new \DateTimeImmutable('2026-07-10 18:00 GMT'));

        static::assertSame('2026-07-10T18:00:00+00:00[UTC]', (string) $zdt);
    }

    public function testNonIanaAbbreviationBecomesFixedOffset(): void
    {
        $zdt = ZonedDateTime::fromDateTime(new \DateTimeImmutable('2026-07-10 20:00 CEST'));

        static::assertSame('2026-07-10T20:00:00+02:00[+02:00]', (string) $zdt);
    }

    public function testAbbreviationThatIsAlsoIanaNameKeepsPhpLocalTime(): void
    {
        $zdt = ZonedDateTime::fromDateTime(new \DateTimeImmutable('2026-07-10 20:00 CET'));

        static::assertSame('2026-07-10T20:00:00+01:00[+01:00]', (string) $zdt);
    }
}
