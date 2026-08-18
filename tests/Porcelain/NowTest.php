<?php

declare(strict_types=1);

namespace Calendrics\Tests\Porcelain;

use Calendrics\Calendar;
use Calendrics\Exception\RangeError;
use Calendrics\Instant;
use Calendrics\Now;
use Calendrics\PlainDate;
use Calendrics\PlainDateTime;
use Calendrics\PlainTime;
use PHPUnit\Framework\TestCase;

final class NowTest extends TestCase
{
    // -------------------------------------------------------------------------
    // timeZoneId
    // -------------------------------------------------------------------------

    public function testTimeZoneIdReturnsSystemDefault(): void
    {
        $original = date_default_timezone_get();

        try {
            date_default_timezone_set('America/New_York');
            static::assertSame('America/New_York', Now::timeZoneId());

            date_default_timezone_set('Europe/London');
            static::assertSame('Europe/London', Now::timeZoneId());
        } finally {
            date_default_timezone_set($original);
        }
    }

    // -------------------------------------------------------------------------
    // instant
    // -------------------------------------------------------------------------

    public function testInstantReturnsPorcelainInstant(): void
    {
        $instant = Now::instant();

        /** @phpstan-ignore staticMethod.alreadyNarrowedType */
        static::assertInstanceOf(Instant::class, $instant);
    }

    public function testInstantEpochNanosecondsAreReasonable(): void
    {
        $before = (int) (microtime(true) * 1_000_000.0) * 1_000;
        $instant = Now::instant();
        $after = (int) (microtime(true) * 1_000_000.0) * 1_000;

        static::assertGreaterThanOrEqual($before, $instant->epochNanoseconds);
        static::assertLessThanOrEqual($after, $instant->epochNanoseconds);
    }

    // -------------------------------------------------------------------------
    // plainDate
    // -------------------------------------------------------------------------

    public function testPlainDateReturnsPorcelainPlainDate(): void
    {
        $date = Now::plainDate('UTC');

        /** @phpstan-ignore staticMethod.alreadyNarrowedType */
        static::assertInstanceOf(PlainDate::class, $date);
    }

    public function testPlainDateWithoutArgumentUsesSystemDefault(): void
    {
        $original = date_default_timezone_get();

        try {
            date_default_timezone_set('UTC');
            $dt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $date = Now::plainDate();

            static::assertSame((int) $dt->format('Y'), $date->year);
            static::assertSame((int) $dt->format('n'), $date->month);
            static::assertSame((int) $dt->format('j'), $date->day);
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testPlainDateWithExplicitTimeZone(): void
    {
        $dt = new \DateTimeImmutable('now', new \DateTimeZone('Pacific/Auckland'));
        $date = Now::plainDate('Pacific/Auckland');

        static::assertSame((int) $dt->format('Y'), $date->year);
        static::assertSame((int) $dt->format('n'), $date->month);
        static::assertSame((int) $dt->format('j'), $date->day);
    }

    public function testPlainDateExplicitNullUsesSystemDefault(): void
    {
        $original = date_default_timezone_get();

        try {
            date_default_timezone_set('UTC');
            $date = Now::plainDate(null);

            static::assertSame(Calendar::Iso8601, $date->calendar);
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testPlainDateEmptyStringThrowsInvalidArgument(): void
    {
        $this->expectException(RangeError::class);
        Now::plainDate('');
    }

    // -------------------------------------------------------------------------
    // plainTime
    // -------------------------------------------------------------------------

    public function testPlainTimeReturnsPorcelainPlainTime(): void
    {
        $time = Now::plainTime('UTC');

        /** @phpstan-ignore staticMethod.alreadyNarrowedType */
        static::assertInstanceOf(PlainTime::class, $time);
    }

    public function testPlainTimeWithoutArgumentUsesSystemDefault(): void
    {
        $original = date_default_timezone_get();

        try {
            date_default_timezone_set('UTC');
            $dt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $time = Now::plainTime();

            // Allow a 2-second window for clock drift between the two calls.
            $expectedSeconds = ((int) $dt->format('G') * 3600) + ((int) $dt->format('i') * 60) + (int) $dt->format('s');
            $actualSeconds = ($time->hour * 3600) + ($time->minute * 60) + $time->second;
            static::assertEqualsWithDelta($expectedSeconds, $actualSeconds, 2);
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testPlainTimeExplicitNullUsesSystemDefault(): void
    {
        $original = date_default_timezone_get();

        try {
            date_default_timezone_set('UTC');
            $time = Now::plainTime(null);

            static::assertGreaterThanOrEqual(0, $time->hour);
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testPlainTimeEmptyStringThrowsInvalidArgument(): void
    {
        $this->expectException(RangeError::class);
        Now::plainTime('');
    }

    // -------------------------------------------------------------------------
    // plainDateTime
    // -------------------------------------------------------------------------

    public function testPlainDateTimeReturnsPorcelainPlainDateTime(): void
    {
        $pdt = Now::plainDateTime('UTC');

        /** @phpstan-ignore staticMethod.alreadyNarrowedType */
        static::assertInstanceOf(PlainDateTime::class, $pdt);
    }

    public function testPlainDateTimeWithoutArgumentUsesSystemDefault(): void
    {
        $original = date_default_timezone_get();

        try {
            date_default_timezone_set('UTC');
            $dt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $pdt = Now::plainDateTime();

            static::assertSame((int) $dt->format('Y'), $pdt->year);
            static::assertSame((int) $dt->format('n'), $pdt->month);
            static::assertSame((int) $dt->format('j'), $pdt->day);
            // Allow a 2-second window for the time component.
            $expectedSeconds = ((int) $dt->format('G') * 3600) + ((int) $dt->format('i') * 60) + (int) $dt->format('s');
            $actualSeconds = ($pdt->hour * 3600) + ($pdt->minute * 60) + $pdt->second;
            static::assertEqualsWithDelta($expectedSeconds, $actualSeconds, 2);
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testPlainDateTimeExplicitNullUsesSystemDefault(): void
    {
        $original = date_default_timezone_get();

        try {
            date_default_timezone_set('UTC');
            $pdt = Now::plainDateTime(null);

            static::assertSame(Calendar::Iso8601, $pdt->calendar);
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testPlainDateTimeEmptyStringThrowsInvalidArgument(): void
    {
        $this->expectException(RangeError::class);
        Now::plainDateTime('');
    }

    // -------------------------------------------------------------------------
    // zonedDateTime
    // -------------------------------------------------------------------------

    public function testZonedDateTimeReturnsPorcelainZonedDateTime(): void
    {
        $zdt = Now::zonedDateTime('UTC');

        static::assertSame('UTC', $zdt->timeZoneId);
    }

    public function testZonedDateTimeWithoutArgumentUsesSystemDefault(): void
    {
        $original = date_default_timezone_get();

        try {
            date_default_timezone_set('America/New_York');
            $zdt = Now::zonedDateTime();
            static::assertSame('America/New_York', $zdt->timeZoneId);
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testZonedDateTimeExplicitNullUsesSystemDefault(): void
    {
        $original = date_default_timezone_get();

        try {
            date_default_timezone_set('America/New_York');
            $zdt = Now::zonedDateTime(null);

            static::assertSame('America/New_York', $zdt->timeZoneId);
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testZonedDateTimeEmptyStringThrowsInvalidArgument(): void
    {
        $this->expectException(RangeError::class);
        Now::zonedDateTime('');
    }

    // -------------------------------------------------------------------------
    // Calendar parameter
    // -------------------------------------------------------------------------

    public function testPlainDateWithCalendar(): void
    {
        $date = Now::plainDate('UTC', Calendar::Hebrew);

        static::assertSame(Calendar::Hebrew, $date->calendar);
    }

    public function testPlainDateDefaultCalendarIsIso(): void
    {
        $date = Now::plainDate('UTC');

        static::assertSame(Calendar::Iso8601, $date->calendar);
    }

    public function testPlainDateTimeWithCalendar(): void
    {
        $pdt = Now::plainDateTime('UTC', Calendar::Japanese);

        static::assertSame(Calendar::Japanese, $pdt->calendar);
    }

    public function testPlainDateTimeDefaultCalendarIsIso(): void
    {
        $pdt = Now::plainDateTime('UTC');

        static::assertSame(Calendar::Iso8601, $pdt->calendar);
    }

    public function testZonedDateTimeWithCalendar(): void
    {
        $zdt = Now::zonedDateTime('UTC', Calendar::Gregory);

        static::assertSame(Calendar::Gregory, $zdt->calendar);
    }

    public function testZonedDateTimeDefaultCalendarIsIso(): void
    {
        $zdt = Now::zonedDateTime('UTC');

        static::assertSame(Calendar::Iso8601, $zdt->calendar);
    }

    // -------------------------------------------------------------------------
    // Not instantiable
    // -------------------------------------------------------------------------

    public function testNotInstantiable(): void
    {
        $ref = new \ReflectionClass(Now::class);

        static::assertFalse($ref->isInstantiable());

        // Cover the private constructor body via reflection.
        $constructor = $ref->getConstructor();
        static::assertNotNull($constructor);
        $instance = $ref->newInstanceWithoutConstructor();
        $constructor->invoke($instance);
    }
}
