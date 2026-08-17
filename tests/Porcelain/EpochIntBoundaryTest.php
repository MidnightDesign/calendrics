<?php

declare(strict_types=1);

namespace Temporal\Tests\Porcelain;

use Temporal\Duration;
use Temporal\Instant;
use Temporal\ZonedDateTime;

/**
 * PHP_INT_MIN and PHP_INT_MAX are ordinary epoch nanosecond counts — 1677-09-21 and
 * 2262-04-11, both far inside the Temporal range — but they are also the values the
 * implementation clamps to when an instant overflows int64. These cover the boundary
 * where the two meanings meet; the test262 corpus cannot, since a JS BigInt has no
 * 64-bit edge to sit on.
 */
final class EpochIntBoundaryTest extends TemporalTestCase
{
    public function testZonedDateTimeAtIntMaxRendersItsInstant(): void
    {
        $zdt = new ZonedDateTime(PHP_INT_MAX, 'UTC');

        static::assertSame('2262-04-11T23:47:16.854775807+00:00[UTC]', $zdt->toString());
    }

    public function testZonedDateTimeAtIntMinRendersItsInstant(): void
    {
        $zdt = new ZonedDateTime(PHP_INT_MIN, 'UTC');

        static::assertSame('1677-09-21T00:12:43.145224192+00:00[UTC]', $zdt->toString());
    }

    public function testZonedDateTimeAtIntMinAddsTimeUnits(): void
    {
        $zdt = new ZonedDateTime(PHP_INT_MIN, 'UTC')->add(new Duration(hours: 1));

        static::assertSame('1677-09-21T01:12:43.145224192+00:00[UTC]', $zdt->toString());
    }

    public function testZonedDateTimeAtIntMaxSubtractsTimeUnits(): void
    {
        $zdt = new ZonedDateTime(PHP_INT_MAX, 'UTC')->subtract(new Duration(hours: 1));

        static::assertSame('2262-04-11T22:47:16.854775807+00:00[UTC]', $zdt->toString());
    }

    public function testInstantAtIntMinRendersItsInstant(): void
    {
        $instant = new Instant(PHP_INT_MIN);

        static::assertSame('1677-09-21T00:12:43.145224192Z', $instant->toString());
    }

    public function testInstantAtIntMinAddsTimeUnits(): void
    {
        $instant = new Instant(PHP_INT_MIN)->add(new Duration(hours: 1));

        static::assertSame('1677-09-21T01:12:43.145224192Z', $instant->toString());
    }

    public function testInstantAtIntMinReportsWholeMillisecondsFlooredTowardNegativeInfinity(): void
    {
        $instant = new Instant(PHP_INT_MIN);

        static::assertSame(-9_223_372_036_855, $instant->epochMilliseconds);
    }
}
