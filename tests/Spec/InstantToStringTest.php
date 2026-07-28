<?php

declare(strict_types=1);

namespace Temporal\Tests\Spec;

use PHPUnit\Framework\TestCase;
use Temporal\Exception\RangeError;
use Temporal\Exception\TypeError;
use Temporal\Spec\Instant;

/**
 * String output and time-zone resolution for {@see Instant}: toString() and its
 * timeZone option, toJSON()/toLocaleString(), and toZonedDateTimeISO().
 * Split from {@see InstantTest} to keep both classes reviewable.
 */
final class InstantToStringTest extends TestCase
{
    // -------------------------------------------------------------------------
    // toString
    // -------------------------------------------------------------------------

    public function testToStringDefaultIsUtcWithAutoPrecision(): void
    {
        static::assertSame('1970-01-01T00:00:00Z', new Instant(0)->toString());
        static::assertSame('1970-01-01T00:00:00.5Z', new Instant(500_000_000)->toString());
        static::assertSame('1970-01-01T00:00:00.000000001Z', new Instant(1)->toString());
    }

    public function testToStringFixedFractionDigits(): void
    {
        $i = new Instant(500_000_000);

        static::assertSame('1970-01-01T00:00:00Z', $i->toString(['fractionalSecondDigits' => 0]));
        static::assertSame('1970-01-01T00:00:00.50Z', $i->toString(['fractionalSecondDigits' => 2]));
        static::assertSame('1970-01-01T00:00:00.500000000Z', $i->toString(['fractionalSecondDigits' => 9]));
    }

    public function testToStringSmallestUnitMinute(): void
    {
        static::assertSame('1970-01-01T00:00Z', new Instant(0)->toString(['smallestUnit' => 'minute']));
    }

    public function testToStringMinuteCeilRoundsUp(): void
    {
        $opts = ['smallestUnit' => 'minute', 'roundingMode' => 'ceil'];

        static::assertSame('1970-01-01T00:01Z', new Instant(1)->toString($opts));
    }

    public function testToStringMinuteRoundingNearSpecMax(): void
    {
        // At the far end of the range, an off-by-one minute increment drifts by
        // whole minutes; this pins the exact truncation.
        $i = Instant::from('+275760-09-12T23:59:30Z');

        static::assertSame('+275760-09-12T23:59Z', $i->toString(['smallestUnit' => 'minute']));
    }

    public function testToStringSmallestUnitVariants(): void
    {
        $i = new Instant(1_400_500_600);

        static::assertSame('1970-01-01T00:00:01Z', $i->toString(['smallestUnit' => 'second']));
        static::assertSame('1970-01-01T00:00:01.400Z', $i->toString(['smallestUnit' => 'millisecond']));
        static::assertSame('1970-01-01T00:00:01.400500Z', $i->toString(['smallestUnit' => 'microsecond']));
        static::assertSame('1970-01-01T00:00:01.400500600Z', $i->toString(['smallestUnit' => 'nanosecond']));
    }

    public function testToStringSmallestUnitOverridesDigits(): void
    {
        $opts = ['smallestUnit' => 'second', 'fractionalSecondDigits' => 5];

        static::assertSame('1970-01-01T00:00:00Z', new Instant(900_000_000)->toString($opts));
    }

    public function testToStringRejectsInvalidSmallestUnit(): void
    {
        $this->expectException(RangeError::class);
        new Instant(0)->toString(['smallestUnit' => 'day']);
    }

    public function testToStringValidatesRoundingModeOnFastPath(): void
    {
        // No rounding happens for 'auto' precision, but the mode must still be
        // validated.
        $this->expectException(RangeError::class);
        new Instant(0)->toString(['roundingMode' => 'bogus']);
    }

    public function testToStringRoundsHalfExpandAsIfPositive(): void
    {
        $opts = ['fractionalSecondDigits' => 0, 'roundingMode' => 'halfExpand'];

        // -1.5s: as-if-positive half-expand rounds the tie upward (toward +∞).
        static::assertSame('1969-12-31T23:59:59Z', new Instant(-1_500_000_000)->toString($opts));
        static::assertSame('1970-01-01T00:00:02Z', new Instant(1_500_000_000)->toString($opts));
    }

    public function testToStringCeilRounding(): void
    {
        $opts = ['fractionalSecondDigits' => 0, 'roundingMode' => 'ceil'];

        static::assertSame('1970-01-01T00:00:01Z', new Instant(1)->toString($opts));
    }

    public function testToStringUtcTimeZoneUsesOffsetSuffix(): void
    {
        static::assertSame('1970-01-01T00:00:00+00:00', new Instant(0)->toString(['timeZone' => 'UTC']));
    }

    public function testToStringOffsetTimeZones(): void
    {
        static::assertSame('1970-01-01T05:30:00+05:30', new Instant(0)->toString(['timeZone' => '+05:30']));
        static::assertSame('1970-01-01T05:30:00+05:30', new Instant(0)->toString(['timeZone' => '+0530']));
        static::assertSame('1969-12-31T19:00:00-05:00', new Instant(0)->toString(['timeZone' => '-05:00']));
    }

    public function testToStringIanaTimeZone(): void
    {
        static::assertSame('1970-01-01T09:00:00+09:00', new Instant(0)->toString(['timeZone' => 'Asia/Tokyo']));
    }

    public function testToStringOffsetSuffixWithLargeMinuteComponent(): void
    {
        static::assertSame('1970-01-01T09:50:00+09:50', new Instant(0)->toString(['timeZone' => '+09:50']));
    }

    public function testToStringNegativeCompactOffset(): void
    {
        static::assertSame('1969-12-31T18:30:00-05:30', new Instant(0)->toString(['timeZone' => '-0530']));
    }

    public function testToStringSubMinuteHistoricOffsetRoundsToNearestMinute(): void
    {
        // Amsterdam's pre-1937 legal time was +00:19:32; the displayed offset
        // rounds to the nearest minute (+00:20), not down.
        $i = Instant::from('1900-01-01T00:00:00Z');

        static::assertSame('1900-01-01T00:19:32+00:20', $i->toString(['timeZone' => 'Europe/Amsterdam']));
    }

    public function testToStringUnknownIanaNameFallsBackToUtc(): void
    {
        // Identifiers that pass the syntactic validation but are unknown to
        // DateTimeZone resolve to offset 0.
        static::assertSame('1970-01-01T00:00:00+00:00', new Instant(0)->toString(['timeZone' => 'UTC+0530']));
        static::assertSame('1970-01-01T00:00:00+00:00', new Instant(0)->toString(['timeZone' => '+05:30x30']));
    }

    public function testToStringGarbageWrappedOffsetsFallBackToUtc(): void
    {
        // Neither a pure offset nor a datetime, and unknown to DateTimeZone:
        // the offset digits embedded in the identifier must not be extracted.
        static::assertSame('1970-01-01T00:00:00+00:00', new Instant(0)->toString(['timeZone' => 'x+05:00x']));
        static::assertSame('1970-01-01T00:00:00+00:00', new Instant(0)->toString(['timeZone' => 'x+05:30']));
    }

    public function testToStringLowercaseZWinsOverLaterOffset(): void
    {
        // The lazy scan hits the case-insensitive 'z' before the offset.
        $opts = ['timeZone' => '2020-01-01t00:00z+05:30'];

        static::assertSame('1970-01-01T00:00:00+00:00', new Instant(0)->toString($opts));
    }

    public function testToStringInvalidBracketFallsBackToInlineZ(): void
    {
        // The bracket names no known zone, so the trailing Z's offset 0 wins.
        $opts = ['timeZone' => '2020-01-01T00:00Z[x+05:30]'];

        static::assertSame('1970-01-01T00:00:00+00:00', new Instant(0)->toString($opts));
    }

    public function testToStringBracketUtcAnnotation(): void
    {
        $opts = ['timeZone' => '2020-01-01T00:00Z[UTC]'];

        static::assertSame('1970-01-01T00:00:00+00:00', new Instant(0)->toString($opts));
    }

    public function testToStringBracketNegativeOffsetAnnotation(): void
    {
        $opts = ['timeZone' => '2020-01-01T00:00Z[-05:30]'];

        static::assertSame('1969-12-31T18:30:00-05:30', new Instant(0)->toString($opts));
    }

    public function testToStringInlineOffsetScanTreatsUtcPrefixAsDatetime(): void
    {
        // The 'T' of "UTC" satisfies the inline-offset scan, so the colon form
        // resolves to its offset rather than falling back to 0.
        static::assertSame('1970-01-01T05:00:00+05:00', new Instant(0)->toString(['timeZone' => 'UTC+05:00']));
    }

    public function testToStringDatetimeTimeZoneWithEmbeddedInvalidBracket(): void
    {
        // The bracket wins over the trailing Z but names no known zone, so the
        // offset falls back to 0.
        $opts = ['timeZone' => '2020-01-01T00:00Z[UTC+05:00:30]'];

        static::assertSame('1970-01-01T00:00:00+00:00', new Instant(0)->toString($opts));
    }

    public function testToStringLowercaseDatetimeTimeZone(): void
    {
        static::assertSame('1970-01-01T00:00:00+00:00', new Instant(0)->toString(['timeZone' => '2020-01-01t00:00z']));
    }

    public function testToStringRejectsMalformedOffsetTimeZones(): void
    {
        foreach (['+05:3+0530', '+05301'] as $tz) {
            try {
                new Instant(0)->toString(['timeZone' => $tz]);
                static::fail("timeZone \"{$tz}\" should be rejected");
            } catch (RangeError $e) {
                static::assertStringContainsString('invalid format', $e->getMessage());
            }
        }
    }

    public function testToStringBracketAnnotationTimeZone(): void
    {
        $opts = ['timeZone' => '2020-01-01T00:00Z[Asia/Tokyo]'];

        static::assertSame('1970-01-01T09:00:00+09:00', new Instant(0)->toString($opts));
    }

    public function testToStringDatetimeTimeZoneWithInlineOffset(): void
    {
        $opts = ['timeZone' => '2020-01-01T00:00+05:30'];

        static::assertSame('1970-01-01T05:30:00+05:30', new Instant(0)->toString($opts));
    }

    public function testToStringRejectsNullTimeZone(): void
    {
        $this->expectException(TypeError::class);
        new Instant(0)->toString(['timeZone' => null]);
    }

    public function testToStringRejectsNonStringTimeZone(): void
    {
        $this->expectException(TypeError::class);
        new Instant(0)->toString(['timeZone' => 123]);
    }

    public function testToStringRejectsEmptyTimeZone(): void
    {
        $this->expectException(RangeError::class);
        new Instant(0)->toString(['timeZone' => '']);
    }

    public function testToStringRejectsMinusZeroYearTimeZone(): void
    {
        $this->expectException(RangeError::class);
        new Instant(0)->toString(['timeZone' => '-000000']);
    }

    public function testToStringRejectsSubMinuteOffsetTimeZone(): void
    {
        $this->expectException(RangeError::class);
        new Instant(0)->toString(['timeZone' => '+05:00:30']);
    }

    public function testToStringRejectsSubMinuteBracketTimeZone(): void
    {
        $this->expectException(RangeError::class);
        new Instant(0)->toString(['timeZone' => '2020-01-01T00:00Z[+05:00:30]']);
    }

    public function testToStringRejectsSubMinuteInlineOffsetTimeZone(): void
    {
        $this->expectException(RangeError::class);
        new Instant(0)->toString(['timeZone' => '2020-01-01T00:00-07:00:01']);
    }

    public function testToStringRejectsBareDatetimeTimeZone(): void
    {
        $this->expectException(RangeError::class);
        new Instant(0)->toString(['timeZone' => '2020-01-01T00:00']);
    }

    public function testToStringNegativeYearUsesExtendedForm(): void
    {
        static::assertSame('-009999-11-18T15:23:30Z', Instant::from('-009999-11-18T15:23:30Z')->toString());
    }

    public function testToStringYearAbove9999UsesExtendedForm(): void
    {
        static::assertSame('+010000-01-01T00:00:00Z', Instant::from('+010000-01-01T00:00Z')->toString());
    }

    public function testToStringYearBoundariesUsePlainForm(): void
    {
        // Year 0 and year 9999 sit exactly on the extended-form boundaries.
        static::assertSame('0000-01-01T00:00:00Z', Instant::from('+000000-01-01T00:00Z')->toString());
        static::assertSame('9999-01-01T00:00:00Z', Instant::from('9999-01-01T00:00Z')->toString());
    }

    public function testToLocaleStringReturnsNonEmptyString(): void
    {
        static::assertNotSame('', new Instant(0)->toLocaleString('en-US', ['dateStyle' => 'short']));
    }

    public function testToStringOverInt64InstantPreservesTrueValue(): void
    {
        static::assertSame('+275760-09-13T00:00:00Z', Instant::from('+275760-09-13T00:00Z')->toString());
        static::assertSame('-271821-04-20T00:00:00Z', Instant::from('-271821-04-20T00:00Z')->toString());
    }

    public function testToJsonAndCastMatchToString(): void
    {
        $i = new Instant(500_000_000);

        static::assertSame($i->toString(), $i->toJSON());
        static::assertSame($i->toString(), (string) $i);
    }

    // -------------------------------------------------------------------------
    // toZonedDateTimeISO
    // -------------------------------------------------------------------------

    public function testToZonedDateTimeISO(): void
    {
        $zdt = new Instant(123)->toZonedDateTimeISO('Asia/Tokyo');

        static::assertSame('Asia/Tokyo', $zdt->timeZoneId);
        static::assertSame(123, $zdt->epochNanoseconds);
    }

    public function testToZonedDateTimeISONormalizesCompactOffset(): void
    {
        static::assertSame('+05:30', new Instant(0)->toZonedDateTimeISO('+0530')->timeZoneId);
    }

    public function testToZonedDateTimeISOBracketAnnotation(): void
    {
        static::assertSame(
            'Asia/Tokyo',
            new Instant(0)->toZonedDateTimeISO('2020-01-01T00:00Z[Asia/Tokyo]')->timeZoneId,
        );
    }

    public function testToZonedDateTimeISOUtcDesignator(): void
    {
        static::assertSame('UTC', new Instant(0)->toZonedDateTimeISO('2020-01-01T00:00Z')->timeZoneId);
    }

    public function testToZonedDateTimeISOInlineOffset(): void
    {
        static::assertSame('+05:30', new Instant(0)->toZonedDateTimeISO('2020-01-01T00:00+05:30')->timeZoneId);
    }

    public function testToZonedDateTimeISORejectsEmptyString(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('must not be empty');
        new Instant(0)->toZonedDateTimeISO('');
    }

    public function testToZonedDateTimeISORejectionMessages(): void
    {
        $cases = [
            ['2020-01-01T00:00Z[+05:00:30]',    'sub-minute offset in bracket annotation'],
            ['2020-01-01T00:00Z[UTC+05:00:30]', 'unsupported bracket timezone'],
            ['2020-01-01T00:00Z[Not/AZone]',    'unsupported bracket timezone'],
            ['2020-01-01T00:00Z[x+05:30]',      'unsupported bracket timezone'],
            ['2020-01-01T00:00Z[+05:30x]',      'unsupported bracket timezone'],
            ['2020-01-01T00:00+05:00:30',       'seconds component'],
            ['2020-01-01T00:00',                'bare datetime'],
            // The full "Invalid time zone string" prefix distinguishes this
            // rejection from ZonedDateTime's own "Invalid timeZoneId" error.
            ['x+05:30',                         'Invalid time zone string "x+05:30": not a recognized'],
            ['+05:30x',                         'Invalid time zone string "+05:30x": not a recognized'],
            ['x+05:30:00',                      'Invalid time zone string "x+05:30:00": not a recognized'],
            ['x+0530',                          'Invalid time zone string "x+0530": not a recognized'],
            ['+05301',                          'Invalid time zone string "+05301": not a recognized'],
        ];

        foreach ($cases as [$tz, $needle]) {
            try {
                new Instant(0)->toZonedDateTimeISO($tz);
                static::fail("toZonedDateTimeISO(\"{$tz}\") should throw");
            } catch (RangeError $e) {
                static::assertStringContainsString($needle, $e->getMessage(), "for \"{$tz}\"");
            }
        }
    }

    public function testToZonedDateTimeISORejectsMinusZeroYear(): void
    {
        $this->expectException(RangeError::class);
        new Instant(0)->toZonedDateTimeISO('-000000');
    }

    public function testToZonedDateTimeISORejectsSubMinuteOffset(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('sub-minute offset');
        new Instant(0)->toZonedDateTimeISO('+05:30:00');
    }

    public function testToZonedDateTimeISORejectsSubMinuteBracket(): void
    {
        $this->expectException(RangeError::class);
        new Instant(0)->toZonedDateTimeISO('2020-01-01T00:00Z[+05:00:30]');
    }

    public function testToZonedDateTimeISORejectsSubMinuteInlineOffset(): void
    {
        $this->expectException(RangeError::class);
        new Instant(0)->toZonedDateTimeISO('2020-01-01T00:00+05:00:30');
    }

    public function testToZonedDateTimeISORejectsBareDatetime(): void
    {
        $this->expectException(RangeError::class);
        new Instant(0)->toZonedDateTimeISO('2020-01-01T00:00');
    }

    public function testToZonedDateTimeISORejectsUnknownBracketTimeZone(): void
    {
        $this->expectException(RangeError::class);
        new Instant(0)->toZonedDateTimeISO('2020-01-01T00:00Z[Not/AZone]');
    }

    public function testToZonedDateTimeISORejectsUnknownTimeZoneName(): void
    {
        $this->expectException(RangeError::class);
        new Instant(0)->toZonedDateTimeISO('Not/AZone');
    }
}
