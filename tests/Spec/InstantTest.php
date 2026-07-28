<?php

declare(strict_types=1);

namespace Temporal\Tests\Spec;

use PHPUnit\Framework\TestCase;
use Temporal\Exception\RangeError;
use Temporal\Exception\TypeError;
use Temporal\Spec\Instant;
use Temporal\Spec\ZonedDateTime;

final class InstantTest extends TestCase
{
    private const int SPEC_MAX_NS_SENTINEL = PHP_INT_MAX;
    private const int SPEC_MIN_NS_SENTINEL = PHP_INT_MIN;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function testConstructorTakesIntVerbatim(): void
    {
        static::assertSame(1_577_836_800_000_000_000, new Instant(1_577_836_800_000_000_000)->epochNanoseconds);
    }

    public function testConstructorCoercesBooleans(): void
    {
        static::assertSame(1, new Instant(true)->epochNanoseconds);
        static::assertSame(0, new Instant(false)->epochNanoseconds);
    }

    public function testConstructorRejectsFloat(): void
    {
        $this->expectException(TypeError::class);
        new Instant(1.5);
    }

    public function testConstructorParsesDecimalStrings(): void
    {
        static::assertSame(-1, new Instant('-1')->epochNanoseconds);
        static::assertSame(0, new Instant('-0')->epochNanoseconds);
        static::assertSame(123, new Instant('+000123')->epochNanoseconds);
        static::assertSame(1_234_567_890, new Instant('1234567890')->epochNanoseconds);
    }

    public function testConstructorParsesNegativeStringWithSubSecondPart(): void
    {
        // -1.5s worth of nanoseconds: floor decomposition must reconstruct exactly.
        $i = new Instant('-1500000000');

        static::assertSame(-1_500_000_000, $i->epochNanoseconds);
        static::assertSame('1969-12-31T23:59:58.5Z', $i->toString());
    }

    public function testConstructorNegativeWholeSecondString(): void
    {
        static::assertSame(-5_000_000_000, new Instant('-5000000000')->epochNanoseconds);
    }

    public function testConstructorOverInt64StringUsesSentinel(): void
    {
        // Spec max: 8.64e21 ns, far beyond int64; the public field saturates.
        static::assertSame(self::SPEC_MAX_NS_SENTINEL, new Instant('8640000000000000000000')->epochNanoseconds);
        static::assertSame(self::SPEC_MIN_NS_SENTINEL, new Instant('-8640000000000000000000')->epochNanoseconds);
    }

    public function testConstructorRejectsStringJustOverSpecMax(): void
    {
        $this->expectException(RangeError::class);
        new Instant('8640000000000000000001');
    }

    public function testConstructorRejectsStringJustUnderSpecMin(): void
    {
        $this->expectException(RangeError::class);
        new Instant('-8640000000000000000001');
    }

    public function testConstructorRejectsNonDecimalString(): void
    {
        $this->expectException(RangeError::class);
        new Instant('1.5');
    }

    // -------------------------------------------------------------------------
    // epochMilliseconds
    // -------------------------------------------------------------------------

    public function testEpochMillisecondsFloorsTowardNegativeInfinity(): void
    {
        static::assertSame(-1, new Instant(-1)->epochMilliseconds);
        static::assertSame(-2, new Instant(-1_000_001)->epochMilliseconds);
        static::assertSame(1, new Instant(1_999_999)->epochMilliseconds);
        static::assertSame(0, new Instant(999_999)->epochMilliseconds);
    }

    public function testEpochMillisecondsSurvivesOverInt64Instants(): void
    {
        static::assertSame(8_640_000_000_000_000, new Instant('8640000000000000000000')->epochMilliseconds);
    }

    // -------------------------------------------------------------------------
    // from
    // -------------------------------------------------------------------------

    public function testFromUtcString(): void
    {
        static::assertSame(1_577_836_800_000_000_000, Instant::from('2020-01-01T00:00:00Z')->epochNanoseconds);
    }

    public function testFromAppliesPositiveOffset(): void
    {
        static::assertSame(0, Instant::from('1970-01-01T05:30:00+05:30')->epochNanoseconds);
    }

    public function testFromAppliesNegativeOffset(): void
    {
        static::assertSame(0, Instant::from('1969-12-31T19:00:00-05:00')->epochNanoseconds);
    }

    public function testFromShortOffsetForms(): void
    {
        static::assertSame(0, Instant::from('1970-01-01T05:30:00+0530')->epochNanoseconds);
        static::assertSame(0, Instant::from('1970-01-01T05:00:00+05')->epochNanoseconds);
    }

    public function testFromSubMinuteOffsetWithFraction(): void
    {
        static::assertSame(-500_000_000, Instant::from('1969-12-31T23:59:59-00:00:00.5')->epochNanoseconds);
    }

    public function testFromFractionalSeconds(): void
    {
        static::assertSame(500_000_000, Instant::from('1970-01-01T00:00:00.5Z')->epochNanoseconds);
        static::assertSame(120_000_000, Instant::from('1970-01-01T00:00:00,12Z')->epochNanoseconds);
    }

    public function testFromBareHourTime(): void
    {
        static::assertSame('1976-11-18T15:00:00Z', Instant::from('1976-11-18T15Z')->toString());
    }

    public function testFromCompactDatetime(): void
    {
        static::assertSame('1976-11-18T15:23:30Z', Instant::from('19761118T152330Z')->toString());
        // Month and day digits differ, pinning the exact compact-date split.
        static::assertSame('1976-12-04T00:00:00Z', Instant::from('19761204T000000Z')->toString());
    }

    public function testFromCompactOffsetWithSeconds(): void
    {
        static::assertSame(-30_000_000_000, Instant::from('1970-01-01T00:00:00+000030')->epochNanoseconds);
    }

    public function testFromCompactOffsetWithCommaFraction(): void
    {
        static::assertSame(-30_500_000_000, Instant::from('1970-01-01T00:00:00+000030,5')->epochNanoseconds);
    }

    public function testFromCompactOffsetWithDotFraction(): void
    {
        static::assertSame(-30_500_000_000, Instant::from('1970-01-01T00:00:00+000030.5')->epochNanoseconds);
    }

    public function testFromColonOffsetWithCommaFraction(): void
    {
        static::assertSame(-500_000_000, Instant::from('1970-01-01T00:00:00+00:00:00,5')->epochNanoseconds);
    }

    public function testFromFractionCarriesAcrossSecondBoundary(): void
    {
        // local .9s minus offset −.2s crosses the second boundary upward.
        static::assertSame(1_100_000_000, Instant::from('1970-01-01T00:00:00.9-00:00:00.2')->epochNanoseconds);
    }

    public function testFromExtendedYears(): void
    {
        static::assertSame('1976-11-18T15:23:30Z', Instant::from('+001976-11-18T15:23:30Z')->toString());
        static::assertSame('-009999-11-18T15:23:30Z', Instant::from('-009999-11-18T15:23:30Z')->toString());
    }

    public function testFromLeapSecondNormalizesTo59(): void
    {
        static::assertSame(1_483_228_799_000_000_000, Instant::from('2016-12-31T23:59:60Z')->epochNanoseconds);
    }

    public function testFromIgnoresAnnotations(): void
    {
        static::assertSame(0, Instant::from('1970-01-01T00:00:00Z[UTC][u-ca=iso8601]')->epochNanoseconds);
    }

    public function testFromSpecBoundaries(): void
    {
        static::assertSame(self::SPEC_MIN_NS_SENTINEL, Instant::from('-271821-04-20T00:00Z')->epochNanoseconds);
        static::assertSame(self::SPEC_MAX_NS_SENTINEL, Instant::from('+275760-09-13T00:00Z')->epochNanoseconds);
    }

    public function testFromExtremeOffsetAtSpecMax(): void
    {
        $i = Instant::from('+275760-09-13T23:59:59.999999999+23:59:59.999999999');

        static::assertSame('+275760-09-13T00:00:00Z', $i->toString());
    }

    public function testFromRejectsJustPastSpecMin(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('Instant string');
        Instant::from('-271821-04-19T23:59:59.999999999Z');
    }

    public function testFromRejectsJustPastSpecMax(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('Instant string');
        Instant::from('+275760-09-13T00:00:00.000000001Z');
    }

    public function testFromInstantReturnsEqualCopy(): void
    {
        $orig = new Instant(123_456_789);
        $copy = Instant::from($orig);

        static::assertNotSame($orig, $copy);
        static::assertSame(123_456_789, $copy->epochNanoseconds);
    }

    public function testFromZonedDateTimeUsesItsEpoch(): void
    {
        $zdt = new ZonedDateTime(123_456_789, 'UTC');

        static::assertSame(123_456_789, Instant::from($zdt)->epochNanoseconds);
    }

    public function testFromRejectsForeignObject(): void
    {
        $this->expectException(TypeError::class);
        Instant::from(new \stdClass());
    }

    public function testFromRejectsMissingOffset(): void
    {
        $this->expectException(RangeError::class);
        Instant::from('2020-01-01T00:00:00');
    }

    public function testFromRejectsMinusZeroYear(): void
    {
        $this->expectException(RangeError::class);
        Instant::from('-000000-01-01T00:00Z');
    }

    public function testFromRejectsMonthZero(): void
    {
        $this->expectException(RangeError::class);
        Instant::from('2020-00-01T00:00Z');
    }

    public function testFromRejectsMonth13(): void
    {
        $this->expectException(RangeError::class);
        Instant::from('2020-13-01T00:00Z');
    }

    public function testFromRejectsDayZero(): void
    {
        $this->expectException(RangeError::class);
        Instant::from('2020-01-00T00:00Z');
    }

    public function testFromRejectsFeb29InNonLeapYear(): void
    {
        $this->expectException(RangeError::class);
        Instant::from('2019-02-29T00:00Z');
    }

    public function testFromAcceptsFeb29InLeapYear(): void
    {
        static::assertSame('2020-02-29T00:00:00Z', Instant::from('2020-02-29T00:00Z')->toString());
    }

    public function testFromRejectsHour24(): void
    {
        $this->expectException(RangeError::class);
        Instant::from('2020-01-01T24:00Z');
    }

    public function testFromRejectsMinute60(): void
    {
        $this->expectException(RangeError::class);
        Instant::from('2020-01-01T00:60Z');
    }

    public function testFromRejectsSecond61(): void
    {
        $this->expectException(RangeError::class);
        Instant::from('2020-01-01T00:00:61Z');
    }

    public function testFromRejectsTenFractionDigits(): void
    {
        $this->expectException(RangeError::class);
        Instant::from('2020-01-01T00:00:00.1234567890Z');
    }

    public function testFromRejectsOffsetOutOfRange(): void
    {
        $this->expectException(RangeError::class);
        Instant::from('2020-01-01T00:00:00+24:00');
    }

    public function testFromRejectsOffsetOutOfRangeWithFraction(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('UTC offset out of range');
        Instant::from('1970-01-01T00:00:00+24:00:00.5');
    }

    public function testFromAcceptsMaximalOffset(): void
    {
        static::assertSame(
            '2019-12-31T00:00:00.000000001Z',
            Instant::from('2020-01-01T00:00:00+23:59:59.999999999')->toString(),
        );
    }

    // -------------------------------------------------------------------------
    // fromEpochMilliseconds / fromEpochNanoseconds
    // -------------------------------------------------------------------------

    public function testFromEpochMilliseconds(): void
    {
        static::assertSame(1_500_000_000, Instant::fromEpochMilliseconds(1_500)->epochNanoseconds);
        static::assertSame(-1_000_000, Instant::fromEpochMilliseconds(-1)->epochNanoseconds);
    }

    public function testFromEpochMillisecondsAcceptsIntegralFloat(): void
    {
        static::assertSame(2_000_000_000, Instant::fromEpochMilliseconds(2000.0)->epochNanoseconds);
    }

    public function testFromEpochMillisecondsRejectsNull(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('must be provided');
        Instant::fromEpochMilliseconds(null);
    }

    public function testFromEpochMillisecondsRejectsFractionalFloat(): void
    {
        $this->expectException(RangeError::class);
        Instant::fromEpochMilliseconds(1.5);
    }

    public function testFromEpochMillisecondsRejectsInfinity(): void
    {
        $this->expectException(RangeError::class);
        Instant::fromEpochMilliseconds(INF);
    }

    public function testFromEpochMillisecondsRejectsBeyondSpecLimit(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('outside the valid range');
        Instant::fromEpochMilliseconds(8_640_000_000_000_001);
    }

    /**
     * @psalm-suppress InternalMethod fromEpochParts is the internal seam the
     * test262 transpiler targets; its float handling is only reachable here.
     */
    public function testFromEpochPartsFloatArguments(): void
    {
        static::assertSame(1_000_000_000, Instant::fromEpochParts(1.0, 0)->epochNanoseconds);
        static::assertSame(500_000_000, Instant::fromEpochParts(0, 5.0e8)->epochNanoseconds);
        static::assertSame(PHP_INT_MAX, Instant::fromEpochParts(8_640_000_000_000.0, 0)->epochNanoseconds);
        static::assertSame(PHP_INT_MIN, Instant::fromEpochParts(-8_640_000_000_000.0, 0)->epochNanoseconds);
    }

    /**
     * @psalm-suppress InternalMethod
     */
    public function testFromEpochPartsRejectsProblematicFloatSubNs(): void
    {
        $cases = [1.5, 1.0e19, (float) PHP_INT_MAX, (float) PHP_INT_MIN];

        foreach ($cases as $bad) {
            try {
                Instant::fromEpochParts(0, $bad);
                static::fail("subNs {$bad} should throw");
            } catch (RangeError $e) {
                static::assertStringContainsString('outside the representable', $e->getMessage());
            }
        }
    }

    /**
     * @psalm-suppress InternalMethod
     */
    public function testFromEpochPartsRejectsNonFiniteFloats(): void
    {
        foreach ([INF, -INF, NAN] as $bad) {
            try {
                Instant::fromEpochParts($bad, 0);
                static::fail('non-finite epochSec should throw');
            } catch (RangeError $e) {
                static::assertStringContainsString('outside the representable', $e->getMessage());
            }
        }
    }

    public function testFromEpochMillisecondsAtSpecLimit(): void
    {
        static::assertSame(
            8_640_000_000_000_000,
            Instant::fromEpochMilliseconds(8_640_000_000_000_000)->epochMilliseconds,
        );
        static::assertSame(
            -8_640_000_000_000_000,
            Instant::fromEpochMilliseconds(-8_640_000_000_000_000)->epochMilliseconds,
        );
    }

    public function testFromEpochMillisecondsOverInt64NsThresholdRoundTrips(): void
    {
        // Just past the largest ms count whose ns equivalent fits int64; the
        // decomposed path must preserve the exact value.
        static::assertSame(9_223_372_036_855, Instant::fromEpochMilliseconds(9_223_372_036_855)->epochMilliseconds);
        static::assertSame(-9_223_372_036_855, Instant::fromEpochMilliseconds(-9_223_372_036_855)->epochMilliseconds);
    }

    public function testFromEpochNanoseconds(): void
    {
        static::assertSame(42, Instant::fromEpochNanoseconds(42)->epochNanoseconds);
    }

    // -------------------------------------------------------------------------
    // compare / equals
    // -------------------------------------------------------------------------

    public function testCompare(): void
    {
        static::assertSame(-1, Instant::compare(new Instant(1), new Instant(2)));
        static::assertSame(1, Instant::compare(new Instant(2), new Instant(1)));
        static::assertSame(0, Instant::compare(new Instant(1), new Instant(1)));
    }

    public function testCompareParsesStrings(): void
    {
        static::assertSame(0, Instant::compare('1970-01-01T00:00:00Z', new Instant(0)));
    }

    public function testCompareOverInt64Instants(): void
    {
        // Both saturate the public field; the true parts must still order them.
        $max = Instant::from('+275760-09-13T00:00Z');
        $nearMax = Instant::from('+275760-09-12T00:00Z');

        static::assertSame(1, Instant::compare($max, $nearMax));
        static::assertSame(-1, Instant::compare($nearMax, $max));
    }

    public function testCompareSubSecondOrdering(): void
    {
        static::assertSame(-1, Instant::compare(new Instant(1), new Instant(2)));
        static::assertSame(1, Instant::compare(new Instant(1_000_000_001), new Instant(1_000_000_000)));
    }

    public function testEquals(): void
    {
        static::assertTrue(new Instant(0)->equals('1970-01-01T00:00:00Z'));
        static::assertFalse(new Instant(0)->equals(new Instant(1)));
    }

    public function testEqualsRejectsInvalidString(): void
    {
        $this->expectException(RangeError::class);
        new Instant(0)->equals('bogus');
    }

    public function testEqualsRejectsNonInstantObject(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('must be an Instant or an ISO string');
        new Instant(0)->equals(new \stdClass());
    }

    public function testEqualsParsesStringableArgument(): void
    {
        $stringable = new class implements \Stringable {
            #[\Override]
            public function __toString(): string
            {
                return '1970-01-01T00:00:00Z';
            }
        };

        static::assertTrue(new Instant(0)->equals($stringable));
    }

    // -------------------------------------------------------------------------
    // add / subtract
    // -------------------------------------------------------------------------

    public function testAddEachTimeUnit(): void
    {
        $zero = new Instant(0);

        static::assertSame(3_600_000_000_000, $zero->add(['hours' => 1])->epochNanoseconds);
        static::assertSame(60_000_000_000, $zero->add(['minutes' => 1])->epochNanoseconds);
        static::assertSame(1_000_000_000, $zero->add(['seconds' => 1])->epochNanoseconds);
        static::assertSame(1_000_000, $zero->add(['milliseconds' => 1])->epochNanoseconds);
        static::assertSame(1_000, $zero->add(['microseconds' => 1])->epochNanoseconds);
        static::assertSame(1, $zero->add(['nanoseconds' => 1])->epochNanoseconds);
    }

    public function testAddDurationString(): void
    {
        static::assertSame(5_400_000_000_000, new Instant(0)->add('PT1H30M')->epochNanoseconds);
    }

    public function testSubtractEachTimeUnit(): void
    {
        $zero = new Instant(0);

        static::assertSame(-3_600_000_000_000, $zero->subtract(['hours' => 1])->epochNanoseconds);
        static::assertSame(-60_000_000_000, $zero->subtract(['minutes' => 1])->epochNanoseconds);
        static::assertSame(-1_000_000_000, $zero->subtract(['seconds' => 1])->epochNanoseconds);
        static::assertSame(-1_000_000, $zero->subtract(['milliseconds' => 1])->epochNanoseconds);
        static::assertSame(-1_000, $zero->subtract(['microseconds' => 1])->epochNanoseconds);
        static::assertSame(-1, $zero->subtract(['nanoseconds' => 1])->epochNanoseconds);
    }

    public function testAddRejectsEachCalendarField(): void
    {
        $zero = new Instant(0);

        foreach (['years', 'months', 'weeks', 'days'] as $field) {
            try {
                $zero->add([$field => 1]);
                static::fail("add() should reject non-zero {$field}");
            } catch (RangeError $e) {
                static::assertStringContainsString('calendar fields', $e->getMessage());
            }
        }
    }

    public function testSubtractRejectsEachCalendarField(): void
    {
        $zero = new Instant(0);

        foreach (['years', 'months', 'weeks', 'days'] as $field) {
            try {
                $zero->subtract([$field => 1]);
                static::fail("subtract() should reject non-zero {$field}");
            } catch (RangeError $e) {
                static::assertStringContainsString('calendar fields', $e->getMessage());
            }
        }
    }

    public function testAddLargeSubSecondFieldsDecomposeExactly(): void
    {
        static::assertSame(1_500_000_000, new Instant(0)->add(['microseconds' => 1_500_000])->epochNanoseconds);
        static::assertSame(2_000_000_000, new Instant(0)->add(['milliseconds' => 2_000])->epochNanoseconds);
        static::assertSame(3_000_000_000, new Instant(0)->add(['nanoseconds' => 3_000_000_000])->epochNanoseconds);
    }

    public function testAddRejectsResultBeyondSpecRange(): void
    {
        $this->expectException(RangeError::class);
        Instant::from('+275760-09-13T00:00Z')->add(['nanoseconds' => 1]);
    }

    public function testSubtractRejectsResultBeyondSpecRange(): void
    {
        $this->expectException(RangeError::class);
        Instant::from('-271821-04-20T00:00Z')->subtract(['nanoseconds' => 1]);
    }

    public function testAddRejectsAstronomicallyLargeDelta(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('arithmetic delta');
        new Instant(0)->add(['seconds' => 100_000_000_000_000]);
    }

    // -------------------------------------------------------------------------
    // round
    // -------------------------------------------------------------------------

    public function testRoundStringArgument(): void
    {
        static::assertSame(2_000_000_000, new Instant(1_500_000_000)->round('second')->epochNanoseconds);
    }

    public function testRoundDefaultsToHalfExpand(): void
    {
        static::assertSame(1_000_000_000, new Instant(1_499_999_999)->round('second')->epochNanoseconds);
    }

    public function testRoundEachUnit(): void
    {
        $i = new Instant(5_400_000_000_000); // 1.5 hours

        static::assertSame(7_200_000_000_000, $i->round('hour')->epochNanoseconds);
        static::assertSame(5_400_000_000_000, $i->round('minute')->epochNanoseconds);
        static::assertSame(2_000_000, new Instant(1_500_000)->round('millisecond')->epochNanoseconds);
        static::assertSame(2_000, new Instant(1_500)->round('microsecond')->epochNanoseconds);
        static::assertSame(42, new Instant(42)->round('nanosecond')->epochNanoseconds);
    }

    public function testRoundTruncAndCeil(): void
    {
        $i = new Instant(1_999_999_999);

        static::assertSame(
            1_000_000_000,
            $i->round(['smallestUnit' => 'second', 'roundingMode' => 'trunc'])->epochNanoseconds,
        );
        static::assertSame(
            2_000_000_000,
            new Instant(1_000_000_001)->round([
                'smallestUnit' => 'second',
                'roundingMode' => 'ceil',
            ])->epochNanoseconds,
        );
    }

    public function testRoundNegativeEpochUsesAsIfPositiveSemantics(): void
    {
        $opts = ['smallestUnit' => 'second', 'roundingMode' => 'halfExpand'];

        // -1.5s: the tie rounds toward +∞, not away from zero.
        static::assertSame(-1_000_000_000, new Instant(-1_500_000_000)->round($opts)->epochNanoseconds);
    }

    public function testRoundWithIncrement(): void
    {
        $opts = ['smallestUnit' => 'minute', 'roundingIncrement' => 15];

        static::assertSame(900_000_000_000, new Instant(500_000_000_000)->round($opts)->epochNanoseconds);
    }

    public function testRoundAllowsIncrementEqualToFullDayDivisor(): void
    {
        // Unlike since/until, round() allows the increment to equal the maximum.
        $opts = ['smallestUnit' => 'hour', 'roundingIncrement' => 24];

        static::assertSame(0, new Instant(1)->round($opts)->epochNanoseconds);
    }

    public function testRoundPluralUnitForms(): void
    {
        static::assertSame(7_200_000_000_000, new Instant(5_400_000_000_000)->round('hours')->epochNanoseconds);
        static::assertSame(120_000_000_000, new Instant(90_000_000_000)->round('minutes')->epochNanoseconds);
        static::assertSame(2_000_000_000, new Instant(1_500_000_000)->round('seconds')->epochNanoseconds);
        static::assertSame(2_000_000, new Instant(1_500_000)->round('milliseconds')->epochNanoseconds);
        static::assertSame(2_000, new Instant(1_500)->round('microseconds')->epochNanoseconds);
        static::assertSame(42, new Instant(42)->round('nanoseconds')->epochNanoseconds);
    }

    public function testRoundPluralUnitsAcceptFullDayIncrement(): void
    {
        // Each unit's maximum increment is the count of that unit in one day.
        $cases = [
            ['nanoseconds',  86_400_000_000_000],
            ['microseconds', 86_400_000_000],
            ['milliseconds', 86_400_000],
            ['seconds',      86_400],
            ['minutes',      1_440],
            ['hours',        24],
        ];

        foreach ($cases as [$unit, $increment]) {
            $rounded = new Instant(1)->round(['smallestUnit' => $unit, 'roundingIncrement' => $increment]);

            static::assertSame(0, $rounded->epochNanoseconds, "unit {$unit}");
        }
    }

    public function testRoundRejectsInvalidRoundingModeWithoutRounding(): void
    {
        // Even at nanosecond precision (a no-op), the mode must be validated.
        $this->expectException(RangeError::class);
        new Instant(0)->round(['smallestUnit' => 'nanosecond', 'roundingMode' => 'bogus']);
    }

    public function testRoundRequiresSmallestUnit(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('requires smallestUnit');
        new Instant(0)->round([]);
    }

    public function testRoundRejectsInvalidUnit(): void
    {
        $this->expectException(RangeError::class);
        new Instant(0)->round('day');
    }

    public function testRoundRejectsZeroIncrement(): void
    {
        $this->expectException(RangeError::class);
        new Instant(0)->round(['smallestUnit' => 'second', 'roundingIncrement' => 0]);
    }

    public function testRoundRejectsNonDivisorIncrement(): void
    {
        $this->expectException(RangeError::class);
        new Instant(0)->round(['smallestUnit' => 'hour', 'roundingIncrement' => 5]);
    }

    public function testRoundRejectsInvalidRoundingMode(): void
    {
        $this->expectException(RangeError::class);
        new Instant(0)->round(['smallestUnit' => 'second', 'roundingMode' => 'bogus']);
    }

    // -------------------------------------------------------------------------
    // since / until
    // -------------------------------------------------------------------------

    public function testUntilDefaultsToSeconds(): void
    {
        static::assertSame(
            'PT5400S',
            new Instant(0)
                ->until(new Instant(5_400_000_000_000))
                ->toString(),
        );
    }

    public function testUntilLargestUnitHour(): void
    {
        $d = new Instant(0)->until(new Instant(5_400_000_000_000), ['largestUnit' => 'hour']);

        static::assertSame('PT1H30M', $d->toString());
    }

    public function testUntilLargestUnitMinute(): void
    {
        $d = new Instant(0)->until(new Instant(5_400_000_000_000), ['largestUnit' => 'minute']);

        static::assertSame('PT90M', $d->toString());
    }

    public function testUntilSubSecondLargestUnits(): void
    {
        $d = new Instant(0)->until(new Instant(2_000_000_000), ['largestUnit' => 'millisecond']);
        static::assertSame(2_000, $d->milliseconds);

        $d = new Instant(0)->until(new Instant(2_000_000_000), ['largestUnit' => 'microsecond']);
        static::assertSame(2_000_000, $d->microseconds);

        $d = new Instant(0)->until(new Instant(2_000_000_000), ['largestUnit' => 'nanosecond']);
        static::assertSame(2_000_000_000, $d->nanoseconds);
    }

    public function testUntilBalancesSubSecondComponents(): void
    {
        $d = new Instant(0)->until(new Instant(1_002_003_004), ['largestUnit' => 'second']);

        static::assertSame(1, $d->seconds);
        static::assertSame(2, $d->milliseconds);
        static::assertSame(3, $d->microseconds);
        static::assertSame(4, $d->nanoseconds);
    }

    public function testUntilNegativeResult(): void
    {
        static::assertSame(
            '-PT90S',
            new Instant(90_000_000_000)
                ->until(new Instant(0))
                ->toString(),
        );
    }

    public function testSinceIsReverseOfUntil(): void
    {
        static::assertSame(
            'PT90S',
            new Instant(90_000_000_000)
                ->since(new Instant(0))
                ->toString(),
        );
        static::assertSame(
            '-PT90S',
            new Instant(0)
                ->since(new Instant(90_000_000_000))
                ->toString(),
        );
    }

    public function testSinceParsesStringOther(): void
    {
        static::assertSame(
            'PT1S',
            new Instant(1_000_000_000)
                ->since('1970-01-01T00:00:00Z')
                ->toString(),
        );
    }

    public function testUntilSmallestUnitDefaultsToTrunc(): void
    {
        $opts = ['smallestUnit' => 'minute'];

        static::assertSame(
            'PT1M',
            new Instant(0)
                ->until(new Instant(119_000_000_000), $opts)
                ->toString(),
        );
    }

    public function testUntilNegativeDiffFloorNegatesMode(): void
    {
        $opts = ['smallestUnit' => 'minute', 'roundingMode' => 'floor'];

        // floor on a negative diff rounds toward -∞.
        static::assertSame(
            '-PT2M',
            new Instant(90_000_000_000)
                ->until(new Instant(0), $opts)
                ->toString(),
        );
    }

    public function testUntilNegativeDiffCeilNegatesMode(): void
    {
        $opts = ['smallestUnit' => 'minute', 'roundingMode' => 'ceil'];

        static::assertSame(
            '-PT1M',
            new Instant(90_000_000_000)
                ->until(new Instant(0), $opts)
                ->toString(),
        );
    }

    public function testUntilHalfCeilAndHalfFloorNegateOnNegativeDiff(): void
    {
        $halfCeil = ['smallestUnit' => 'minute', 'roundingMode' => 'halfCeil'];
        $halfFloor = ['smallestUnit' => 'minute', 'roundingMode' => 'halfFloor'];

        static::assertSame(
            '-PT1M',
            new Instant(90_000_000_000)
                ->until(new Instant(0), $halfCeil)
                ->toString(),
        );
        static::assertSame(
            '-PT2M',
            new Instant(90_000_000_000)
                ->until(new Instant(0), $halfFloor)
                ->toString(),
        );
    }

    public function testUntilWithRoundingIncrement(): void
    {
        $opts = ['smallestUnit' => 'second', 'roundingIncrement' => 30];

        static::assertSame(
            'PT30S',
            new Instant(0)
                ->until(new Instant(45_000_000_000), $opts)
                ->toString(),
        );
    }

    public function testUntilRejectsSmallestUnitLargerThanLargestUnit(): void
    {
        $this->expectException(RangeError::class);
        new Instant(0)->until(new Instant(1), ['largestUnit' => 'second', 'smallestUnit' => 'minute']);
    }

    public function testUntilRejectsInvalidLargestUnit(): void
    {
        $this->expectException(RangeError::class);
        new Instant(0)->until(new Instant(1), ['largestUnit' => 'day']);
    }

    public function testUntilRejectsInvalidSmallestUnit(): void
    {
        $this->expectException(RangeError::class);
        new Instant(0)->until(new Instant(1), ['smallestUnit' => 'week']);
    }

    public function testUntilRejectsIncrementEqualToMax(): void
    {
        // For since/until the increment must be strictly below the max (24 for
        // hours), unlike round().
        $this->expectException(RangeError::class);
        new Instant(0)->until(new Instant(1), ['smallestUnit' => 'hour', 'roundingIncrement' => 24]);
    }

    public function testUntilRejectsNonDivisorIncrement(): void
    {
        $this->expectException(RangeError::class);
        new Instant(0)->until(new Instant(1), ['smallestUnit' => 'second', 'roundingIncrement' => 25]);
    }

    public function testUntilRejectsZeroIncrement(): void
    {
        $this->expectException(RangeError::class);
        new Instant(0)->until(new Instant(1), ['smallestUnit' => 'second', 'roundingIncrement' => 0]);
    }

    public function testUntilRejectsInvalidRoundingModeOnNoOpPath(): void
    {
        // Even with the default nanosecond precision (no rounding), the mode
        // must be validated.
        $this->expectException(RangeError::class);
        new Instant(0)->until(new Instant(1), ['roundingMode' => 'bogus']);
    }

    public function testUntilSubSecondNegativeDiff(): void
    {
        // A diff of −0.5s exercises the borrow across the second boundary.
        static::assertSame(
            '-PT0.5S',
            new Instant(500_000_000)
                ->until(new Instant(0))
                ->toString(),
        );
        static::assertSame(
            'PT0.5S',
            new Instant(0)
                ->until(new Instant(500_000_000))
                ->toString(),
        );
    }

    public function testUntilSelfIsZeroDuration(): void
    {
        static::assertSame(
            'PT0S',
            new Instant(42)
                ->until(new Instant(42))
                ->toString(),
        );
    }

    public function testUntilNegativeMixedSecondAndSubSecond(): void
    {
        $d = new Instant(1_500_000_000)->until(new Instant(0));

        static::assertSame(-1, $d->seconds);
        static::assertSame(-500, $d->milliseconds);
    }

    public function testUntilPluralUnitOptions(): void
    {
        $d = new Instant(0)->until(new Instant(5_400_000_000_000), [
            'largestUnit' => 'hours',
            'smallestUnit' => 'minutes',
        ]);

        static::assertSame('PT1H30M', $d->toString());
    }

    public function testUntilSecondsIncrementRoundsExactly(): void
    {
        // Pins the exact ns-per-second increment (an off-by-one leaves a
        // nanosecond residue).
        $opts = ['smallestUnit' => 'seconds', 'roundingIncrement' => 30];

        static::assertSame(
            'PT30S',
            new Instant(0)
                ->until(new Instant(45_000_000_000), $opts)
                ->toString(),
        );
    }

    public function testUntilHoursSmallestUnitRoundsExactly(): void
    {
        $opts = ['smallestUnit' => 'hours', 'largestUnit' => 'hours'];

        static::assertSame(
            'PT1H',
            new Instant(0)
                ->until(new Instant(5_400_000_000_000), $opts)
                ->toString(),
        );
    }

    public function testAddOverInt64FloatNanoseconds(): void
    {
        // 1e19 + 5e8 ns exceeds int64, so the field arrives as a float and takes
        // the float decomposition path.
        $i = new Instant(0)->add(['nanoseconds' => 1.0e19 + 5.0e8]);

        static::assertSame(10_000_000_000_500, $i->epochMilliseconds);
    }

    public function testUntilOverInt64SpanSurvives(): void
    {
        // The full spec range spans ~1.75e13 seconds — beyond int64 nanoseconds.
        $d = Instant::from('-271821-04-20T00:00Z')->until(Instant::from('+275760-09-13T00:00Z'));

        static::assertSame(17_280_000_000_000, $d->seconds);
    }
}
