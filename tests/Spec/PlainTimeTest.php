<?php

declare(strict_types=1);

namespace Temporal\Tests\Spec;

use PHPUnit\Framework\TestCase;
use Temporal\Exception\RangeError;
use Temporal\Exception\TypeError;
use Temporal\Spec\Duration;
use Temporal\Spec\PlainDate;
use Temporal\Spec\PlainTime;

final class PlainTimeTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Constructor and virtual properties
    // -------------------------------------------------------------------------

    public function testPropertiesAtUpperBoundary(): void
    {
        $t = new PlainTime(23, 59, 59, 999, 999, 999);

        static::assertSame(23, $t->hour);
        static::assertSame(59, $t->minute);
        static::assertSame(59, $t->second);
        static::assertSame(999, $t->millisecond);
        static::assertSame(999, $t->microsecond);
        static::assertSame(999, $t->nanosecond);
    }

    public function testPropertiesMidRange(): void
    {
        $t = new PlainTime(12, 34, 56, 123, 456, 789);

        static::assertSame(12, $t->hour);
        static::assertSame(34, $t->minute);
        static::assertSame(56, $t->second);
        static::assertSame(123, $t->millisecond);
        static::assertSame(456, $t->microsecond);
        static::assertSame(789, $t->nanosecond);
    }

    public function testConstructorDefaultsToMidnight(): void
    {
        static::assertSame('00:00:00', new PlainTime()->toString());
    }

    public function testConstructorCoercesStringsAndFloats(): void
    {
        $t = new PlainTime('12', 34.9, true);

        static::assertSame(12, $t->hour);
        static::assertSame(34, $t->minute);
        static::assertSame(1, $t->second);
    }

    public function testConstructorRejectsHour24(): void
    {
        $this->expectException(RangeError::class);
        new PlainTime(24);
    }

    public function testConstructorRejectsMinute60(): void
    {
        $this->expectException(RangeError::class);
        new PlainTime(0, 60);
    }

    public function testConstructorRejectsSecond60(): void
    {
        $this->expectException(RangeError::class);
        new PlainTime(0, 0, 60);
    }

    public function testConstructorRejectsMillisecond1000(): void
    {
        $this->expectException(RangeError::class);
        new PlainTime(0, 0, 0, 1000);
    }

    public function testConstructorRejectsMicrosecond1000(): void
    {
        $this->expectException(RangeError::class);
        new PlainTime(0, 0, 0, 0, 1000);
    }

    public function testConstructorRejectsNanosecond1000(): void
    {
        $this->expectException(RangeError::class);
        new PlainTime(0, 0, 0, 0, 0, 1000);
    }

    public function testConstructorRejectsNegativeHour(): void
    {
        $this->expectException(RangeError::class);
        new PlainTime(-1);
    }

    // -------------------------------------------------------------------------
    // from
    // -------------------------------------------------------------------------

    public function testFromClonesInstance(): void
    {
        $t = PlainTime::from(new PlainTime(1, 2, 3, 4, 5, 6));

        static::assertSame('01:02:03.004005006', $t->toString());
    }

    public function testFromInstanceValidatesOptionsPrimitive(): void
    {
        $this->expectException(TypeError::class);
        PlainTime::from(new PlainTime(1), 42);
    }

    public function testFromStringParsesBeforeOptionsValidation(): void
    {
        // ParseISODateTime runs before GetOptionsObject, so the string's
        // RangeError wins over the bad options argument's TypeError.
        $this->expectException(RangeError::class);
        PlainTime::from('bogus', 42);
    }

    public function testFromColonSeparatedString(): void
    {
        static::assertSame('12:34:56.123456789', PlainTime::from('12:34:56.123456789')->toString());
    }

    public function testFromCompactString(): void
    {
        static::assertSame('12:34:56', PlainTime::from('123456')->toString());
    }

    public function testFromHoursOnlyWithDesignator(): void
    {
        static::assertSame('12:00:00', PlainTime::from('T12')->toString());
    }

    public function testFromCompactWithDesignator(): void
    {
        static::assertSame('12:14:00', PlainTime::from('T1214')->toString());
    }

    public function testFromLeapSecondColonForm(): void
    {
        static::assertSame('23:59:59', PlainTime::from('23:59:60')->toString());
    }

    public function testFromLeapSecondCompactForm(): void
    {
        static::assertSame('23:59:59', PlainTime::from('235960')->toString());
    }

    public function testFromLeapSecondFullDatetimeForm(): void
    {
        static::assertSame('23:59:59', PlainTime::from('2021-01-01T23:59:60')->toString());
    }

    public function testFromFullDatetimeDiscardsDate(): void
    {
        static::assertSame('09:08:07', PlainTime::from('2021-12-31T09:08:07+05:30')->toString());
    }

    public function testFromCompactDateDatetime(): void
    {
        static::assertSame('09:08:00', PlainTime::from('20211231T0908')->toString());
    }

    public function testFromCommaFraction(): void
    {
        static::assertSame('12:34:56.5', PlainTime::from('12:34:56,5')->toString());
    }

    public function testFromRejectsEmptyString(): void
    {
        $this->expectException(RangeError::class);
        PlainTime::from('');
    }

    public function testFromRejectsUnicodeMinus(): void
    {
        $this->expectException(RangeError::class);
        PlainTime::from("12:34\u{2212}");
    }

    public function testFromRejectsTenFractionDigits(): void
    {
        $this->expectException(RangeError::class);
        PlainTime::from('12:34:56.1234567890');
    }

    public function testFromRejectsZDesignator(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage("UTC designator 'Z' is not allowed");
        PlainTime::from('12:34:56Z');
    }

    public function testFromRejectsZDesignatorInDatetime(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage("UTC designator 'Z' is not allowed");
        PlainTime::from('2021-01-01T12:34:56Z');
    }

    public function testFromRejectsLowercaseZDesignatorInDatetime(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage("UTC designator 'Z' is not allowed");
        PlainTime::from('2021-01-01T12:34z');
    }

    public function testFromRejectsZBeforeAnnotation(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage("UTC designator 'Z' is not allowed");
        PlainTime::from('12:34Z[UTC]');
    }

    public function testFromDigitsAroundHyphenIsPlainParseFailure(): void
    {
        // "112-14" embeds a valid MonthDay tail ("12-14") but is not itself a
        // date spec, so it fails as a malformed time, not as ambiguous.
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('invalid ISO 8601 time string');
        PlainTime::from('112-14');
    }

    public function testFromInteriorZIsNotAUtcDesignator(): void
    {
        // 'Z' in the middle of the string is a plain parse failure, not the
        // UTC-designator rejection.
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('invalid ISO 8601 time string');
        PlainTime::from('12Z34');
    }

    public function testFromRejectsMinusZeroYearDatetime(): void
    {
        $this->expectException(RangeError::class);
        PlainTime::from('-000000-01-01T12:34');
    }

    public function testFromRejectsAmbiguousYearMonth(): void
    {
        $this->expectException(RangeError::class);
        PlainTime::from('2021-12');
    }

    public function testFromRejectsAmbiguousCompactYearMonth(): void
    {
        $this->expectException(RangeError::class);
        PlainTime::from('202112');
    }

    public function testFromRejectsAmbiguousMonthDay(): void
    {
        $this->expectException(RangeError::class);
        PlainTime::from('12-14');
    }

    public function testFromRejectsAmbiguousCompactMonthDay(): void
    {
        $this->expectException(RangeError::class);
        PlainTime::from('1214');
    }

    public function testFromRejectsAmbiguousDoubleDashMonthDay(): void
    {
        $this->expectException(RangeError::class);
        PlainTime::from('--12-14');
    }

    public function testFromRejectsLeapDayAsAmbiguous(): void
    {
        // Feb 29 exists in the reference leap year 1972, so 0229 is a valid
        // MonthDay spec and therefore ambiguous.
        $this->expectException(RangeError::class);
        PlainTime::from('0229');
    }

    public function testFromAcceptsNonDateCompactTime(): void
    {
        // Feb 30 is not a valid MonthDay even in a leap year, so 0230 is
        // unambiguously a time.
        static::assertSame('02:30:00', PlainTime::from('0230')->toString());
    }

    public function testFromRejectsFractionWithoutSeconds(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('invalid ISO 8601 time string');
        PlainTime::from('1234.5');
    }

    public function testFromRejectsEmptyStringMessage(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('received an empty string');
        PlainTime::from('');
    }

    public function testFromRejectsUnicodeMinusMessage(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('non-ASCII minus sign');
        PlainTime::from("12:34\u{2212}");
    }

    public function testFromRejectsMinuteOverflowInColonForm(): void
    {
        // 99 minutes must be rejected at parse time, not folded into hours.
        $this->expectException(RangeError::class);
        PlainTime::from('00:99');
    }

    public function testFromRejectsSecondOverflowInColonForm(): void
    {
        $this->expectException(RangeError::class);
        PlainTime::from('00:00:99');
    }

    public function testFromRejectsMinuteOverflowInCompactForm(): void
    {
        $this->expectException(RangeError::class);
        PlainTime::from('0099');
    }

    public function testFromRejectsMinuteOverflowInDatetimeForm(): void
    {
        $this->expectException(RangeError::class);
        PlainTime::from('2021-01-01T00:99');
    }

    public function testFromAcceptsSixDigitTimeWithMonthDayPrefix(): void
    {
        // Six-digit strings are only ambiguous when they form a YearMonth
        // (YYYYMM); an embedded MonthDay ("0115" at the end of "020115") must
        // not trigger the rejection.
        static::assertSame('02:15:30', PlainTime::from('021530')->toString());
        static::assertSame('02:01:15', PlainTime::from('020115')->toString());
    }

    public function testFromAcceptsCompactTimeWithFractionAndDatePrefix(): void
    {
        // "1205" + "03.5": a YearMonth prefix inside a longer time string is not
        // ambiguous.
        static::assertSame('12:05:03.5', PlainTime::from('120503.5')->toString());
    }

    public function testFromRejectsAmbiguousJanuaryYearMonth(): void
    {
        $this->expectException(RangeError::class);
        PlainTime::from('202101');
    }

    public function testFromRejectsAmbiguousMonthDayBoundaries(): void
    {
        // Month 1 and day 1 sit exactly on the validity boundary of the
        // DateSpecMonthDay production.
        try {
            PlainTime::from('0114');
            static::fail('0114 should be ambiguous with January 14');
        } catch (RangeError $e) {
            static::assertStringContainsString('ambiguous', $e->getMessage());
        }
        try {
            PlainTime::from('1201');
            static::fail('1201 should be ambiguous with December 1');
        } catch (RangeError $e) {
            static::assertStringContainsString('ambiguous', $e->getMessage());
        }
    }

    public function testFromAllowsCalendarAnnotation(): void
    {
        static::assertSame('12:34:00', PlainTime::from('12:34[u-ca=iso8601]')->toString());
    }

    public function testFromRejectsCriticalUnknownAnnotation(): void
    {
        $this->expectException(RangeError::class);
        PlainTime::from('12:34[!foo=bar]');
    }

    public function testFromRejectsCriticalUnknownAnnotationCompactForm(): void
    {
        $this->expectException(RangeError::class);
        PlainTime::from('0230[!foo=bar]');
    }

    public function testFromIgnoresUnknownCalendarAnnotation(): void
    {
        // PlainTime does not validate the calendar annotation's value.
        static::assertSame('12:34:00', PlainTime::from('2021-01-01T12:34[u-ca=bogus]')->toString());
        static::assertSame('12:34:00', PlainTime::from('12:34[u-ca=bogus]')->toString());
        static::assertSame('02:30:45', PlainTime::from('023045[u-ca=bogus]')->toString());
    }

    public function testFromPropertyBagDefaultsMissingFieldsToZero(): void
    {
        static::assertSame('00:30:00', PlainTime::from(['minute' => 30])->toString());
    }

    public function testFromPropertyBagRejectsEmptyBag(): void
    {
        $this->expectException(TypeError::class);
        PlainTime::from([]);
    }

    public function testFromPropertyBagRejectsAllNullFields(): void
    {
        $this->expectException(TypeError::class);
        PlainTime::from(['hour' => null, 'minute' => null]);
    }

    public function testFromPropertyBagConstrainsByDefault(): void
    {
        static::assertSame('23:00:00', PlainTime::from(['hour' => 25])->toString());
    }

    public function testFromPropertyBagConstrainClampsNegative(): void
    {
        static::assertSame('00:59:00', PlainTime::from(['hour' => -3, 'minute' => 61])->toString());
    }

    public function testFromPropertyBagRejectOverflowThrows(): void
    {
        $this->expectException(RangeError::class);
        PlainTime::from(['hour' => 25], ['overflow' => 'reject']);
    }

    public function testFromPropertyBagRejectAcceptsInRangeWithMissingFields(): void
    {
        // Missing fields default to 0, which must pass 'reject' validation.
        static::assertSame('00:30:00', PlainTime::from(['minute' => 30], ['overflow' => 'reject'])->toString());
    }

    public function testFromPropertyBagConstrainBoundaries(): void
    {
        /** @param array<string, int> $bag */
        $expect = static function (string $expected, array $bag): void {
            static::assertSame($expected, PlainTime::from($bag)->toString(['fractionalSecondDigits' => 9]));
        };

        // One field over the maximum clamps to the maximum...
        $expect('23:00:00.000000000', ['hour' => 24]);
        $expect('00:59:00.000000000', ['minute' => 60]);
        $expect('00:00:59.000000000', ['second' => 60]);
        $expect('00:00:00.999000000', ['millisecond' => 1000]);
        $expect('00:00:00.000999000', ['microsecond' => 1000]);
        $expect('00:00:00.000000999', ['nanosecond' => 1000]);
        // ...the maximum itself is preserved...
        $expect('23:00:00.000000000', ['hour' => 23]);
        $expect('00:59:00.000000000', ['minute' => 59]);
        $expect('00:00:59.000000000', ['second' => 59]);
        $expect('00:00:00.999000000', ['millisecond' => 999]);
        $expect('00:00:00.000999000', ['microsecond' => 999]);
        $expect('00:00:00.000000999', ['nanosecond' => 999]);
        // ...and negative values clamp to zero.
        $expect('00:00:00.000000000', ['hour' => -1]);
        $expect('00:00:00.000000000', ['minute' => -1]);
        $expect('00:00:00.000000000', ['second' => -1]);
        $expect('00:00:00.000000000', ['millisecond' => -1]);
        $expect('00:00:00.000000000', ['microsecond' => -1]);
        $expect('00:00:00.000000000', ['nanosecond' => -1]);
    }

    public function testFromPropertyBagAllFields(): void
    {
        $t = PlainTime::from([
            'hour' => 1,
            'minute' => 2,
            'second' => 3,
            'millisecond' => 4,
            'microsecond' => 5,
            'nanosecond' => 6,
        ]);

        static::assertSame('01:02:03.004005006', $t->toString());
    }

    // -------------------------------------------------------------------------
    // compare / equals
    // -------------------------------------------------------------------------

    public function testCompareOrdersByNanosecond(): void
    {
        static::assertSame(-1, PlainTime::compare(new PlainTime(1, 0, 0, 0, 0, 1), new PlainTime(1, 0, 0, 0, 0, 2)));
        static::assertSame(1, PlainTime::compare(new PlainTime(2), new PlainTime(1)));
        static::assertSame(0, PlainTime::compare('12:34', new PlainTime(12, 34)));
    }

    public function testEquals(): void
    {
        static::assertTrue(new PlainTime(12, 34)->equals('12:34'));
        static::assertFalse(new PlainTime(12, 34)->equals(new PlainTime(12, 34, 0, 0, 0, 1)));
    }

    // -------------------------------------------------------------------------
    // with
    // -------------------------------------------------------------------------

    public function testWithReplacesIndividualFields(): void
    {
        $t = new PlainTime(1, 2, 3, 4, 5, 6);

        static::assertSame('09:02:03.004005006', $t->with(['hour' => 9])->toString());
        static::assertSame('01:09:03.004005006', $t->with(['minute' => 9])->toString());
        static::assertSame('01:02:09.004005006', $t->with(['second' => 9])->toString());
        static::assertSame('01:02:03.009005006', $t->with(['millisecond' => 9])->toString());
        static::assertSame('01:02:03.004009006', $t->with(['microsecond' => 9])->toString());
        static::assertSame('01:02:03.004005009', $t->with(['nanosecond' => 9])->toString());
    }

    public function testWithRejectsEveryTemporalObjectType(): void
    {
        $temporals = [
            new PlainTime(2),
            new PlainDate(2024, 1, 1),
            new \Temporal\Spec\PlainDateTime(2024, 1, 1),
            new \Temporal\Spec\PlainYearMonth(2024, 1),
            new \Temporal\Spec\PlainMonthDay(12, 25),
            new \Temporal\Spec\ZonedDateTime(0, 'UTC'),
            new \Temporal\Spec\Instant(0),
            new Duration(),
        ];

        foreach ($temporals as $temporal) {
            try {
                new PlainTime(1)->with($temporal);
                static::fail(sprintf('with(%s) should throw', get_debug_type($temporal)));
            } catch (TypeError $e) {
                static::assertStringContainsString('must not be a Temporal object', $e->getMessage());
            }
        }
    }

    public function testWithRejectsStringableWithMessage(): void
    {
        $stringable = new class implements \Stringable {
            #[\Override]
            public function __toString(): string
            {
                return 'undefined';
            }
        };

        try {
            new PlainTime(1)->with($stringable);
            static::fail('with(Stringable) should throw');
        } catch (TypeError $e) {
            static::assertStringContainsString('must be an object', $e->getMessage());
        }
    }

    public function testWithRejectsCalendarKey(): void
    {
        $this->expectException(TypeError::class);
        new PlainTime(1)->with(['hour' => 2, 'calendar' => 'iso8601']);
    }

    public function testWithRejectsTimeZoneKey(): void
    {
        $this->expectException(TypeError::class);
        new PlainTime(1)->with(['hour' => 2, 'timeZone' => 'UTC']);
    }

    public function testWithRejectsNoRecognizedFields(): void
    {
        $this->expectException(TypeError::class);
        new PlainTime(1)->with(['month' => 2]);
    }

    public function testWithFieldErrorWinsOverBadOptions(): void
    {
        // ToTemporalTimeRecord coerces fields before GetOptionsObject validates
        // the options argument, so the field's RangeError comes first.
        $this->expectException(RangeError::class);
        new PlainTime(1)->with(['minute' => INF], 42);
    }

    public function testWithConstrainClampsOutOfRange(): void
    {
        static::assertSame('23:59:00', new PlainTime(1, 2)->with(['hour' => 99, 'minute' => 99])->toString());
        static::assertSame('00:00:00', new PlainTime(1, 2)->with(['hour' => -5, 'minute' => -5])->toString());
    }

    public function testWithConstrainBoundaries(): void
    {
        $midnight = new PlainTime();
        /** @param array<string, int> $fields */
        $expect = static function (string $expected, array $fields) use ($midnight): void {
            static::assertSame($expected, $midnight->with($fields)->toString(['fractionalSecondDigits' => 9]));
        };

        $expect('00:00:59.000000000', ['second' => 60]);
        $expect('00:00:00.999000000', ['millisecond' => 1000]);
        $expect('00:00:00.000999000', ['microsecond' => 1000]);
        $expect('00:00:00.000000999', ['nanosecond' => 1000]);
        $expect('00:00:59.000000000', ['second' => 59]);
        $expect('00:00:00.999000000', ['millisecond' => 999]);
        $expect('00:00:00.000999000', ['microsecond' => 999]);
        $expect('00:00:00.000000999', ['nanosecond' => 999]);
        $expect('00:00:00.000000000', ['second' => -1]);
        $expect('00:00:00.000000000', ['millisecond' => -1]);
        $expect('00:00:00.000000000', ['microsecond' => -1]);
        $expect('00:00:00.000000000', ['nanosecond' => -1]);
    }

    public function testWithRejectOverflowThrows(): void
    {
        $this->expectException(RangeError::class);
        new PlainTime(1)->with(['hour' => 24], ['overflow' => 'reject']);
    }

    // -------------------------------------------------------------------------
    // add / subtract
    // -------------------------------------------------------------------------

    public function testAddWrapsAroundMidnight(): void
    {
        static::assertSame('01:00:00', new PlainTime(23)->add(['hours' => 2])->toString());
    }

    public function testSubtractWrapsAroundMidnight(): void
    {
        static::assertSame('23:00:00', new PlainTime(1)->subtract(['hours' => 2])->toString());
    }

    public function testAddEachUnitReducedModuloItsDayCycle(): void
    {
        $noon = new PlainTime(12);

        static::assertSame('13:00:00', $noon->add(['hours' => 25])->toString());
        static::assertSame('12:01:00', $noon->add(['minutes' => 1441])->toString());
        static::assertSame('12:00:01', $noon->add(['seconds' => 86_401])->toString());
        static::assertSame('12:00:00.001', $noon->add(['milliseconds' => 86_400_001])->toString());
        static::assertSame('12:00:00.000001', $noon->add(['microseconds' => 86_400_000_001])->toString());
        static::assertSame('12:00:00.000000001', $noon->add(['nanoseconds' => 86_400_000_000_001])->toString());
    }

    public function testAddIgnoresCalendarFields(): void
    {
        static::assertSame('12:00:00', new PlainTime(12)->add(['days' => 1])->toString());
        static::assertSame('12:00:00', new PlainTime(12)->add(new Duration(1, 2, 3, 4))->toString());
    }

    public function testAddDurationString(): void
    {
        static::assertSame('13:30:00', new PlainTime(12)->add('PT1H30M')->toString());
    }

    public function testSubtractNegativeDurationAdds(): void
    {
        static::assertSame('13:00:00', new PlainTime(12)->subtract(['hours' => -1])->toString());
    }

    // -------------------------------------------------------------------------
    // until / since
    // -------------------------------------------------------------------------

    public function testUntilPositiveDifference(): void
    {
        static::assertSame(
            'PT1H30M15S',
            new PlainTime(12)
                ->until('13:30:15')
                ->toString(),
        );
    }

    public function testUntilNegativeDifference(): void
    {
        static::assertSame(
            '-PT1H30M15S',
            new PlainTime(13, 30, 15)
                ->until('12:00')
                ->toString(),
        );
    }

    public function testSincePositiveDifference(): void
    {
        static::assertSame(
            'PT1H30M15S',
            new PlainTime(13, 30, 15)
                ->since('12:00')
                ->toString(),
        );
    }

    public function testSinceNegativeDifference(): void
    {
        static::assertSame(
            '-PT1H30M15S',
            new PlainTime(12)
                ->since('13:30:15')
                ->toString(),
        );
    }

    public function testUntilSubSecondBalancing(): void
    {
        $d = new PlainTime(0)->until('00:00:01.002003004');

        static::assertSame(1, $d->seconds);
        static::assertSame(2, $d->milliseconds);
        static::assertSame(3, $d->microseconds);
        static::assertSame(4, $d->nanoseconds);
    }

    public function testUntilLargestUnitMinute(): void
    {
        static::assertSame(
            'PT90M',
            new PlainTime(12)
                ->until('13:30', ['largestUnit' => 'minute'])
                ->toString(),
        );
    }

    public function testUntilLargestUnitSecond(): void
    {
        static::assertSame(
            'PT5400S',
            new PlainTime(12)
                ->until('13:30', ['largestUnit' => 'second'])
                ->toString(),
        );
    }

    public function testUntilLargestUnitMillisecond(): void
    {
        $d = new PlainTime(12)->until('12:00:01.5', ['largestUnit' => 'millisecond']);

        static::assertSame(1500, $d->milliseconds);
    }

    public function testUntilLargestUnitMicrosecond(): void
    {
        $d = new PlainTime(12)->until('12:00:00.0015', ['largestUnit' => 'microsecond']);

        static::assertSame(1500, $d->microseconds);
    }

    public function testUntilLargestUnitNanosecond(): void
    {
        $d = new PlainTime(12)->until('12:00:00.0000015', ['largestUnit' => 'nanosecond']);

        static::assertSame(1500, $d->nanoseconds);
    }

    public function testUntilLargestUnitAutoIsHour(): void
    {
        static::assertSame(
            'PT1H30M',
            new PlainTime(12)
                ->until('13:30', ['largestUnit' => 'auto'])
                ->toString(),
        );
    }

    public function testUntilSmallestUnitRoundsTruncByDefault(): void
    {
        static::assertSame(
            'PT1H',
            new PlainTime(12)
                ->until('13:59', ['smallestUnit' => 'hour'])
                ->toString(),
        );
    }

    public function testUntilSmallestUnitHalfExpand(): void
    {
        $opts = ['smallestUnit' => 'hour', 'roundingMode' => 'halfExpand'];

        static::assertSame(
            'PT2H',
            new PlainTime(12)
                ->until('13:59', $opts)
                ->toString(),
        );
    }

    public function testUntilNegativeDiffFloorRoundsTowardNegativeInfinity(): void
    {
        $opts = ['smallestUnit' => 'hour', 'roundingMode' => 'floor'];

        static::assertSame(
            '-PT2H',
            new PlainTime(13, 30)
                ->until('12:00', $opts)
                ->toString(),
        );
    }

    public function testUntilNegativeDiffCeilRoundsTowardPositiveInfinity(): void
    {
        $opts = ['smallestUnit' => 'hour', 'roundingMode' => 'ceil'];

        static::assertSame(
            '-PT1H',
            new PlainTime(13, 30)
                ->until('12:00', $opts)
                ->toString(),
        );
    }

    public function testUntilExpandRoundsAwayFromZeroBothSigns(): void
    {
        $opts = ['smallestUnit' => 'hour', 'roundingMode' => 'expand'];

        static::assertSame(
            'PT2H',
            new PlainTime(12)
                ->until('13:30', $opts)
                ->toString(),
        );
        static::assertSame(
            '-PT2H',
            new PlainTime(13, 30)
                ->until('12:00', $opts)
                ->toString(),
        );
    }

    public function testUntilHalfEvenTieGoesToEvenQuotient(): void
    {
        $opts = ['smallestUnit' => 'minute', 'roundingMode' => 'halfEven'];

        // 90s is a tie between 1 and 2 minutes; 1 is odd, so round to 2.
        static::assertSame(
            'PT2M',
            new PlainTime(12)
                ->until('12:01:30', $opts)
                ->toString(),
        );
        // 150s is a tie between 2 and 3 minutes; 2 is even, so stay at 2.
        static::assertSame(
            'PT2M',
            new PlainTime(12)
                ->until('12:02:30', $opts)
                ->toString(),
        );
    }

    public function testUntilHalfFloorTie(): void
    {
        $opts = ['smallestUnit' => 'minute', 'roundingMode' => 'halfFloor'];

        static::assertSame(
            'PT1M',
            new PlainTime(12)
                ->until('12:01:30', $opts)
                ->toString(),
        );
        static::assertSame(
            '-PT2M',
            new PlainTime(12, 1, 30)
                ->until('12:00', $opts)
                ->toString(),
        );
    }

    public function testUntilHalfCeilTie(): void
    {
        $opts = ['smallestUnit' => 'minute', 'roundingMode' => 'halfCeil'];

        static::assertSame(
            'PT2M',
            new PlainTime(12)
                ->until('12:01:30', $opts)
                ->toString(),
        );
        static::assertSame(
            '-PT1M',
            new PlainTime(12, 1, 30)
                ->until('12:00', $opts)
                ->toString(),
        );
    }

    public function testUntilHalfExpandBelowMidpointTruncates(): void
    {
        // 25 minutes past the hour is below the midpoint, so halfExpand keeps 1h.
        $opts = ['smallestUnit' => 'hour', 'roundingMode' => 'halfExpand'];

        static::assertSame(
            'PT1H',
            new PlainTime(12)
                ->until('13:25', $opts)
                ->toString(),
        );
    }

    public function testUntilHalfTruncBelowAndAboveTie(): void
    {
        $opts = ['smallestUnit' => 'minute', 'roundingMode' => 'halfTrunc'];

        static::assertSame(
            'PT1M',
            new PlainTime(12)
                ->until('12:01:30', $opts)
                ->toString(),
        );
        static::assertSame(
            'PT2M',
            new PlainTime(12)
                ->until('12:01:31', $opts)
                ->toString(),
        );
    }

    public function testUntilRoundingIncrement(): void
    {
        $opts = ['smallestUnit' => 'minute', 'roundingIncrement' => 15];

        static::assertSame(
            'PT45M',
            new PlainTime(12)
                ->until('12:59', $opts)
                ->toString(),
        );
    }

    public function testUntilRejectsLargestUnitSmallerThanSmallestUnit(): void
    {
        $this->expectException(RangeError::class);
        new PlainTime(12)->until('13:00', ['largestUnit' => 'second', 'smallestUnit' => 'minute']);
    }

    public function testUntilRejectsInvalidLargestUnit(): void
    {
        $this->expectException(RangeError::class);
        new PlainTime(12)->until('13:00', ['largestUnit' => 'day']);
    }

    public function testUntilRejectsInvalidSmallestUnit(): void
    {
        $this->expectException(RangeError::class);
        new PlainTime(12)->until('13:00', ['smallestUnit' => 'day']);
    }

    public function testUntilRejectsNonDivisorIncrement(): void
    {
        $this->expectException(RangeError::class);
        new PlainTime(12)->until('13:00', ['smallestUnit' => 'minute', 'roundingIncrement' => 25]);
    }

    public function testUntilRejectsIncrementEqualToMax(): void
    {
        $this->expectException(RangeError::class);
        new PlainTime(12)->until('13:00', ['smallestUnit' => 'minute', 'roundingIncrement' => 60]);
    }

    // -------------------------------------------------------------------------
    // round
    // -------------------------------------------------------------------------

    public function testRoundStringArgumentIsSmallestUnit(): void
    {
        static::assertSame(
            '13:00:00',
            new PlainTime(12, 30)
                ->round('hour')
                ->toString(),
        );
    }

    public function testRoundDefaultsToHalfExpand(): void
    {
        static::assertSame(
            '12:00:00',
            new PlainTime(12, 29, 59)
                ->round('hour')
                ->toString(),
        );
    }

    public function testRoundTrunc(): void
    {
        $t = new PlainTime(12, 59, 59)->round(['smallestUnit' => 'hour', 'roundingMode' => 'trunc']);

        static::assertSame('12:00:00', $t->toString());
    }

    public function testRoundCeilWrapsToMidnight(): void
    {
        $t = new PlainTime(23, 0, 1)->round(['smallestUnit' => 'hour', 'roundingMode' => 'ceil']);

        static::assertSame('00:00:00', $t->toString());
    }

    public function testRoundHalfEvenTies(): void
    {
        $opts = ['smallestUnit' => 'minute', 'roundingMode' => 'halfEven'];

        static::assertSame(
            '00:02:00',
            new PlainTime(0, 1, 30)
                ->round($opts)
                ->toString(),
        );
        static::assertSame(
            '00:02:00',
            new PlainTime(0, 2, 30)
                ->round($opts)
                ->toString(),
        );
    }

    public function testRoundWithIncrement(): void
    {
        $t = new PlainTime(12, 44)->round(['smallestUnit' => 'minute', 'roundingIncrement' => 30]);

        static::assertSame('12:30:00', $t->toString());
    }

    public function testRoundEachUnit(): void
    {
        $t = new PlainTime(12, 34, 56, 500, 500, 500);

        static::assertSame('12:34:57', $t->round('second')->toString());
        static::assertSame('12:34:56.501', $t->round('millisecond')->toString());
        static::assertSame('12:34:56.500501', $t->round('microsecond')->toString());
        static::assertSame('12:34:56.5005005', $t->round('nanosecond')->toString());
    }

    public function testRoundRequiresSmallestUnit(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('requires smallestUnit');
        new PlainTime(12)->round([]);
    }

    public function testRoundRejectsInvalidUnit(): void
    {
        $this->expectException(RangeError::class);
        new PlainTime(12)->round('day');
    }

    public function testRoundRejectsIncrementEqualToMax(): void
    {
        $this->expectException(RangeError::class);
        new PlainTime(12)->round(['smallestUnit' => 'hour', 'roundingIncrement' => 24]);
    }

    public function testRoundRejectsNonDivisorIncrement(): void
    {
        $this->expectException(RangeError::class);
        new PlainTime(12)->round(['smallestUnit' => 'second', 'roundingIncrement' => 7]);
    }

    public function testRoundRejectsZeroIncrement(): void
    {
        $this->expectException(RangeError::class);
        new PlainTime(12)->round(['smallestUnit' => 'second', 'roundingIncrement' => 0]);
    }

    // -------------------------------------------------------------------------
    // toString
    // -------------------------------------------------------------------------

    public function testToStringAutoOmitsZeroFraction(): void
    {
        static::assertSame('12:34:56', new PlainTime(12, 34, 56)->toString());
    }

    public function testToStringAutoStripsTrailingZeros(): void
    {
        static::assertSame('12:34:56.5', new PlainTime(12, 34, 56, 500)->toString());
    }

    public function testToStringExplicitNullThrowsTypeError(): void
    {
        $this->expectException(TypeError::class);
        new PlainTime(12)->toString(null);
    }

    public function testToStringFixedDigitsPadWithZeros(): void
    {
        $t = new PlainTime(12, 34, 56, 500);

        static::assertSame('12:34:56.50', $t->toString(['fractionalSecondDigits' => 2]));
        static::assertSame('12:34:56.500000000', $t->toString(['fractionalSecondDigits' => 9]));
    }

    public function testToStringDigitsZeroOmitsFraction(): void
    {
        static::assertSame('12:34:56', new PlainTime(12, 34, 56, 999)->toString(['fractionalSecondDigits' => 0]));
    }

    public function testToStringTruncatesByDefault(): void
    {
        static::assertSame('12:34:56.9', new PlainTime(12, 34, 56, 999)->toString(['fractionalSecondDigits' => 1]));
    }

    public function testToStringSmallestUnitMinute(): void
    {
        static::assertSame('12:34', new PlainTime(12, 34, 56)->toString(['smallestUnit' => 'minute']));
    }

    public function testToStringSmallestUnitOverridesDigits(): void
    {
        $opts = ['smallestUnit' => 'second', 'fractionalSecondDigits' => 5];

        static::assertSame('12:34:56', new PlainTime(12, 34, 56, 999)->toString($opts));
    }

    public function testToStringSmallestUnitVariants(): void
    {
        $t = new PlainTime(1, 2, 3, 400, 500, 600);

        static::assertSame('01:02:03.400', $t->toString(['smallestUnit' => 'millisecond']));
        static::assertSame('01:02:03.400500', $t->toString(['smallestUnit' => 'microsecond']));
        static::assertSame('01:02:03.400500600', $t->toString(['smallestUnit' => 'nanosecond']));
    }

    public function testToStringRejectsInvalidSmallestUnit(): void
    {
        $this->expectException(RangeError::class);
        new PlainTime(12)->toString(['smallestUnit' => 'hour']);
    }

    public function testToStringCeilRoundingAtEachDigitCount(): void
    {
        // 00:00:00.000000001 ceil-rounded at each precision must round the last
        // displayed digit up, pinning each digit's rounding increment.
        $t = new PlainTime(0, 0, 0, 0, 0, 1);

        static::assertSame('00:00', $t->toString(['smallestUnit' => 'minute', 'roundingMode' => 'trunc']));
        static::assertSame('00:00:00.1', $t->toString(['fractionalSecondDigits' => 1, 'roundingMode' => 'ceil']));
        static::assertSame('00:00:00.01', $t->toString(['fractionalSecondDigits' => 2, 'roundingMode' => 'ceil']));
        static::assertSame('00:00:00.001', $t->toString(['fractionalSecondDigits' => 3, 'roundingMode' => 'ceil']));
        static::assertSame('00:00:00.0001', $t->toString(['fractionalSecondDigits' => 4, 'roundingMode' => 'ceil']));
        static::assertSame('00:00:00.00001', $t->toString(['fractionalSecondDigits' => 5, 'roundingMode' => 'ceil']));
        static::assertSame('00:00:00.000001', $t->toString(['fractionalSecondDigits' => 6, 'roundingMode' => 'ceil']));
        static::assertSame('00:00:00.0000001', $t->toString(['fractionalSecondDigits' => 7, 'roundingMode' => 'ceil']));
        static::assertSame('00:00:00.00000001', $t->toString([
            'fractionalSecondDigits' => 8,
            'roundingMode' => 'ceil',
        ]));
        static::assertSame('00:00:00.000000001', $t->toString([
            'fractionalSecondDigits' => 9,
            'roundingMode' => 'ceil',
        ]));
    }

    public function testToStringTruncPreservesExactMultipleAtEachDigitCount(): void
    {
        // At each precision d, a value of exactly 2×10^(9−d) ns must display as
        // "2" in the last place under trunc — pinning each digit's exact
        // rounding increment.
        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $d) {
            $ns = 2 * (10 ** (9 - $d));
            $t = new PlainTime(
                0,
                0,
                0,
                intdiv(num1: $ns, num2: 1_000_000),
                intdiv(num1: $ns % 1_000_000, num2: 1_000),
                $ns % 1_000,
            );
            $expected = sprintf('00:00:00.%s2', str_repeat('0', $d - 1));

            static::assertSame($expected, $t->toString(['fractionalSecondDigits' => $d]), "digits {$d}");
        }
    }

    public function testToStringNineDigitsKeepsFinalNanosecondDigit(): void
    {
        $opts = ['fractionalSecondDigits' => 9];

        static::assertSame('00:00:00.123456789', new PlainTime(0, 0, 0, 123, 456, 789)->toString($opts));
    }

    public function testToStringMinuteCeilWrapsToMidnight(): void
    {
        $opts = ['smallestUnit' => 'minute', 'roundingMode' => 'ceil'];

        static::assertSame('00:00', new PlainTime(23, 59, 59)->toString($opts));
    }

    public function testToStringRejectsInvalidRoundingMode(): void
    {
        $this->expectException(RangeError::class);
        new PlainTime(12)->toString(['roundingMode' => 'nope']);
    }
}
