<?php

declare(strict_types=1);

namespace Temporal\Tests\Spec;

use PHPUnit\Framework\TestCase;
use Temporal\Exception\RangeError;
use Temporal\Exception\TypeError;
use Temporal\Spec\Now;

final class NowTest extends TestCase
{
    // -------------------------------------------------------------------------
    // instant
    // -------------------------------------------------------------------------

    public function testInstantEpochNanosecondsWithinClockBounds(): void
    {
        $before = (int) (microtime(true) * 1_000_000.0) * 1_000;
        $instant = Now::instant();
        $after = (int) (microtime(true) * 1_000_000.0) * 1_000;

        static::assertGreaterThanOrEqual($before, $instant->epochNanoseconds);
        static::assertLessThanOrEqual($after, $instant->epochNanoseconds);
    }

    public function testInstantHasMicrosecondPrecision(): void
    {
        static::assertSame(0, Now::instant()->epochNanoseconds % 1_000);
    }

    // -------------------------------------------------------------------------
    // timeZoneId
    // -------------------------------------------------------------------------

    public function testTimeZoneIdReflectsSystemDefault(): void
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
    // plainDateISO
    // -------------------------------------------------------------------------

    public function testPlainDateISODefaultsToSystemTimeZone(): void
    {
        $original = date_default_timezone_get();

        try {
            date_default_timezone_set('Pacific/Kiritimati');
            $tz = new \DateTimeZone('Pacific/Kiritimati');
            $before = new \DateTimeImmutable('now', $tz)->format('Y-m-d');
            $date = Now::plainDateISO();
            $after = new \DateTimeImmutable('now', $tz)->format('Y-m-d');

            $actual = sprintf('%04d-%02d-%02d', $date->year, $date->month, $date->day);
            static::assertGreaterThanOrEqual($before, $actual);
            static::assertLessThanOrEqual($after, $actual);
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testPlainDateISOExplicitTimeZone(): void
    {
        $tz = new \DateTimeZone('Pacific/Kiritimati');
        $before = new \DateTimeImmutable('now', $tz)->format('Y-m-d');
        $date = Now::plainDateISO('Pacific/Kiritimati');
        $after = new \DateTimeImmutable('now', $tz)->format('Y-m-d');

        $actual = sprintf('%04d-%02d-%02d', $date->year, $date->month, $date->day);
        static::assertGreaterThanOrEqual($before, $actual);
        static::assertLessThanOrEqual($after, $actual);
    }

    public function testPlainDateISODiffersAcrossDatelineZones(): void
    {
        // UTC+14 and UTC-12 are 26 hours apart, so their civil dates never coincide.
        $west = Now::plainDateISO('Etc/GMT+12');
        $east = Now::plainDateISO('Pacific/Kiritimati');

        $westDate = sprintf('%04d-%02d-%02d', $west->year, $west->month, $west->day);
        $eastDate = sprintf('%04d-%02d-%02d', $east->year, $east->month, $east->day);
        static::assertNotSame($westDate, $eastDate);
        static::assertGreaterThan($westDate, $eastDate);
    }

    public function testPlainDateISODatetimeStringUsesAnnotationTimeZone(): void
    {
        // The [Pacific/Kiritimati] annotation must win over the conflicting -07:00
        // inline offset; only the time zone is taken from the string, never the date.
        $tz = new \DateTimeZone('Pacific/Kiritimati');
        $before = new \DateTimeImmutable('now', $tz)->format('Y-m-d');
        $date = Now::plainDateISO('2021-08-19T17:30-07:00[Pacific/Kiritimati]');
        $after = new \DateTimeImmutable('now', $tz)->format('Y-m-d');

        $actual = sprintf('%04d-%02d-%02d', $date->year, $date->month, $date->day);
        static::assertGreaterThanOrEqual($before, $actual);
        static::assertLessThanOrEqual($after, $actual);
    }

    public function testPlainDateISOExplicitNullThrowsTypeError(): void
    {
        $this->expectException(TypeError::class);
        Now::plainDateISO(null);
    }

    public function testPlainDateISOEmptyStringThrowsRangeError(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('must not be empty');
        Now::plainDateISO('');
    }

    public function testPlainDateISOMinusZeroYearThrowsRangeError(): void
    {
        $this->expectException(RangeError::class);
        Now::plainDateISO('-000000');
    }

    public function testPlainDateISOBareDatetimeThrowsRangeError(): void
    {
        $this->expectException(RangeError::class);
        Now::plainDateISO('2021-08-19T17:30');
    }

    public function testPlainDateISOSubMinuteAnnotationThrowsRangeError(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('sub-minute offset in time zone annotation');
        Now::plainDateISO('2021-08-19T17:30[+05:00:30]');
    }

    public function testPlainDateISOInvalidTimeZoneNameThrowsRangeError(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('invalid time zone identifier');
        Now::plainDateISO('Not/AZone');
    }

    public function testPlainDateISOInvalidAnnotationTimeZoneThrowsRangeError(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('invalid time zone identifier');
        Now::plainDateISO('2021-01-01T00:00Z[Not/AZone]');
    }

    public function testPlainDateISOGarbagePrefixedDatetimeThrowsRangeError(): void
    {
        // A leading non-digit keeps the string from being a datetime; it must be
        // treated (and rejected) as a standalone identifier instead.
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('invalid time zone identifier');
        Now::plainDateISO('x2021-01-01T00:00Z');
    }

    public function testPlainDateISOAnnotationWithEmbeddedSubMinuteOffsetThrowsRangeError(): void
    {
        // The sub-minute rejection only applies to annotations that ARE offsets;
        // "UTC+05:00:30" is not an offset annotation, just an invalid identifier.
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('invalid time zone identifier');
        Now::plainDateISO('2021-01-01T00:00[UTC+05:00:30]');
    }

    public function testPlainDateISOTrailingGarbageAfterOffsetThrowsRangeError(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('no time zone information');
        Now::plainDateISO('2021-01-01T00:00+05:30x');
    }

    // -------------------------------------------------------------------------
    // plainTimeISO
    // -------------------------------------------------------------------------

    public function testPlainTimeISODefaultsToSystemTimeZone(): void
    {
        $original = date_default_timezone_get();

        try {
            date_default_timezone_set('Asia/Kathmandu');
            $tz = new \DateTimeZone('Asia/Kathmandu');
            $before = new \DateTimeImmutable('now', $tz)->format('H:i:s');
            $time = Now::plainTimeISO();
            $after = new \DateTimeImmutable('now', $tz)->format('H:i:s');

            $actual = sprintf('%02d:%02d:%02d', $time->hour, $time->minute, $time->second);
            $this->assertTimeBetween($before, $after, $actual);
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testPlainTimeISOExplicitTimeZone(): void
    {
        $tz = new \DateTimeZone('Asia/Kathmandu');
        $before = new \DateTimeImmutable('now', $tz)->format('H:i:s');
        $time = Now::plainTimeISO('Asia/Kathmandu');
        $after = new \DateTimeImmutable('now', $tz)->format('H:i:s');

        $actual = sprintf('%02d:%02d:%02d', $time->hour, $time->minute, $time->second);
        $this->assertTimeBetween($before, $after, $actual);
    }

    public function testPlainTimeISOOffsetTimeZone(): void
    {
        $tz = new \DateTimeZone('+05:30');
        $before = new \DateTimeImmutable('now', $tz)->format('H:i:s');
        $time = Now::plainTimeISO('+05:30');
        $after = new \DateTimeImmutable('now', $tz)->format('H:i:s');

        $actual = sprintf('%02d:%02d:%02d', $time->hour, $time->minute, $time->second);
        $this->assertTimeBetween($before, $after, $actual);
    }

    public function testPlainTimeISOExplicitNullThrowsTypeError(): void
    {
        $this->expectException(TypeError::class);
        Now::plainTimeISO(null);
    }

    public function testPlainTimeISOEmptyStringThrowsRangeError(): void
    {
        $this->expectException(RangeError::class);
        Now::plainTimeISO('');
    }

    public function testPlainTimeISOStandaloneSubMinuteOffsetThrowsRangeError(): void
    {
        $this->expectException(RangeError::class);
        Now::plainTimeISO('+05:00:30');
    }

    public function testPlainTimeISOCompactSubMinuteOffsetThrowsRangeError(): void
    {
        $this->expectException(RangeError::class);
        Now::plainTimeISO('+0500:30');
    }

    public function testPlainTimeISOEmbeddedSubMinutePatternIsInvalidIdentifier(): void
    {
        // The sub-minute rejection only applies to strings that START with an
        // offset; "UTC+05:00:30" is just an invalid identifier.
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('invalid time zone identifier');
        Now::plainTimeISO('UTC+05:00:30');
    }

    public function testPlainTimeISOStandaloneSubMinuteOffsetMessage(): void
    {
        // Distinguishes the sub-minute rejection from the generic invalid-identifier
        // rejection that would otherwise catch the same string.
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('sub-minute precision');
        Now::plainTimeISO('+05:00:30');
    }

    public function testPlainTimeISOTrailingGarbageAfterAnnotationThrowsRangeError(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('no time zone information');
        Now::plainTimeISO('2021-01-01T00:00Z[Asia/Tokyo]x');
    }

    public function testPlainTimeISODatetimeWithZBeforeOffsetUsesOffset(): void
    {
        // 'Z' not at the end of the string is not a UTC designator; the trailing
        // offset wins.
        $tz = new \DateTimeZone('+05:30');
        $before = new \DateTimeImmutable('now', $tz)->format('H:i:s');
        $time = Now::plainTimeISO('2021-01-01T00:00Z+05:30');
        $after = new \DateTimeImmutable('now', $tz)->format('H:i:s');

        $actual = sprintf('%02d:%02d:%02d', $time->hour, $time->minute, $time->second);
        $this->assertTimeBetween($before, $after, $actual);
    }

    public function testPlainTimeISOOffsetWithTrailingWhitespace(): void
    {
        // The trailing whitespace is not part of the extracted offset.
        $tz = new \DateTimeZone('+05:30');
        $before = new \DateTimeImmutable('now', $tz)->format('H:i:s');
        $time = Now::plainTimeISO('2021-01-01T00:00+05:30 ');
        $after = new \DateTimeImmutable('now', $tz)->format('H:i:s');

        $actual = sprintf('%02d:%02d:%02d', $time->hour, $time->minute, $time->second);
        $this->assertTimeBetween($before, $after, $actual);
    }

    // -------------------------------------------------------------------------
    // plainDateTimeISO
    // -------------------------------------------------------------------------

    public function testPlainDateTimeISODefaultsToSystemTimeZone(): void
    {
        $original = date_default_timezone_get();

        try {
            date_default_timezone_set('Asia/Kathmandu');
            $tz = new \DateTimeZone('Asia/Kathmandu');
            $before = new \DateTimeImmutable('now', $tz)->format('Y-m-d\TH:i:s');
            $dt = Now::plainDateTimeISO();
            $after = new \DateTimeImmutable('now', $tz)->format('Y-m-d\TH:i:s');

            $actual = sprintf(
                '%04d-%02d-%02dT%02d:%02d:%02d',
                $dt->year,
                $dt->month,
                $dt->day,
                $dt->hour,
                $dt->minute,
                $dt->second,
            );
            static::assertGreaterThanOrEqual($before, $actual);
            static::assertLessThanOrEqual($after, $actual);
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testPlainDateTimeISOExplicitTimeZone(): void
    {
        $tz = new \DateTimeZone('Pacific/Kiritimati');
        $before = new \DateTimeImmutable('now', $tz)->format('Y-m-d\TH:i:s');
        $dt = Now::plainDateTimeISO('Pacific/Kiritimati');
        $after = new \DateTimeImmutable('now', $tz)->format('Y-m-d\TH:i:s');

        $actual = sprintf(
            '%04d-%02d-%02dT%02d:%02d:%02d',
            $dt->year,
            $dt->month,
            $dt->day,
            $dt->hour,
            $dt->minute,
            $dt->second,
        );
        static::assertGreaterThanOrEqual($before, $actual);
        static::assertLessThanOrEqual($after, $actual);
    }

    public function testPlainDateTimeISOExplicitNullThrowsTypeError(): void
    {
        $this->expectException(TypeError::class);
        Now::plainDateTimeISO(null);
    }

    public function testPlainDateTimeISOEmptyStringThrowsRangeError(): void
    {
        $this->expectException(RangeError::class);
        Now::plainDateTimeISO('');
    }

    public function testPlainDateTimeISOSubMinuteInlineOffsetThrowsRangeError(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('sub-minute UTC offset');
        Now::plainDateTimeISO('2021-08-19T17:30:00+05:00:30');
    }

    // -------------------------------------------------------------------------
    // zonedDateTimeISO
    // -------------------------------------------------------------------------

    public function testZonedDateTimeISODefaultTimeZoneAndClockBounds(): void
    {
        $original = date_default_timezone_get();

        try {
            date_default_timezone_set('America/New_York');
            $before = (int) (microtime(true) * 1_000_000.0) * 1_000;
            $zdt = Now::zonedDateTimeISO();
            $after = (int) (microtime(true) * 1_000_000.0) * 1_000;

            static::assertSame('America/New_York', $zdt->timeZoneId);
            static::assertGreaterThanOrEqual($before, $zdt->epochNanoseconds);
            static::assertLessThanOrEqual($after, $zdt->epochNanoseconds);
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testZonedDateTimeISOMicrosecondPrecision(): void
    {
        static::assertSame(0, Now::zonedDateTimeISO('UTC')->epochNanoseconds % 1_000);
    }

    public function testZonedDateTimeISOExplicitIanaTimeZone(): void
    {
        static::assertSame('Asia/Tokyo', Now::zonedDateTimeISO('Asia/Tokyo')->timeZoneId);
    }

    public function testZonedDateTimeISOStandaloneOffset(): void
    {
        static::assertSame('+05:30', Now::zonedDateTimeISO('+05:30')->timeZoneId);
    }

    public function testZonedDateTimeISOUtcDesignatorDatetime(): void
    {
        static::assertSame('UTC', Now::zonedDateTimeISO('2021-08-19T17:30Z')->timeZoneId);
    }

    public function testZonedDateTimeISOLowercaseUtcDesignatorCompactDatetime(): void
    {
        static::assertSame('UTC', Now::zonedDateTimeISO('20210819T1730z')->timeZoneId);
    }

    public function testZonedDateTimeISOInlineOffsetDatetime(): void
    {
        static::assertSame('+05:30', Now::zonedDateTimeISO('2021-08-19T17:30+05:30')->timeZoneId);
    }

    public function testZonedDateTimeISOInlineCompactOffsetDatetime(): void
    {
        static::assertSame('+05:30', Now::zonedDateTimeISO('2021-08-19T17:30+0530')->timeZoneId);
    }

    public function testZonedDateTimeISOAnnotationWinsOverInlineOffset(): void
    {
        static::assertSame('Asia/Tokyo', Now::zonedDateTimeISO('2021-08-19T17:30-07:00[Asia/Tokyo]')->timeZoneId);
    }

    public function testZonedDateTimeISOExplicitNullThrowsTypeError(): void
    {
        $this->expectException(TypeError::class);
        Now::zonedDateTimeISO(null);
    }

    public function testZonedDateTimeISOEmptyStringThrowsRangeError(): void
    {
        $this->expectException(RangeError::class);
        Now::zonedDateTimeISO('');
    }

    public function testZonedDateTimeISOBareDatetimeThrowsRangeError(): void
    {
        $this->expectException(RangeError::class);
        Now::zonedDateTimeISO('2021-08-19T17:30');
    }

    public function testZonedDateTimeISOMinusZeroYearThrowsRangeError(): void
    {
        $this->expectException(RangeError::class);
        Now::zonedDateTimeISO('-000000');
    }

    public function testZonedDateTimeISOInvalidTimeZoneNameThrowsRangeError(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('invalid time zone identifier');
        Now::zonedDateTimeISO('Not/AZone');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Asserts that a wall-clock time (HH:MM:SS) lies between two reference reads,
     * accounting for the midnight wraparound between the two reads.
     */
    private function assertTimeBetween(string $before, string $after, string $actual): void
    {
        if ($before <= $after) {
            static::assertGreaterThanOrEqual($before, $actual);
            static::assertLessThanOrEqual($after, $actual);
            return;
        }
        // The reads straddle midnight: the actual time must be after the first
        // read (pre-midnight) or before the second one (post-midnight).
        static::assertTrue($actual >= $before || $actual <= $after, sprintf(
            'Time %s should be between %s and %s (across midnight).',
            $actual,
            $before,
            $after,
        ));
    }
}
