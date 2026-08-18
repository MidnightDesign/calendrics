<?php

declare(strict_types=1);

namespace Calendrics\Tests\Porcelain;

use Calendrics\Calendar;
use Calendrics\CalendarDisplay;
use Calendrics\Duration;
use Calendrics\Exception\RangeError;
use Calendrics\Overflow;
use Calendrics\PlainDateTime;
use Calendrics\PlainTime;
use Calendrics\RoundingMode;
use Calendrics\Unit;

final class PlainDateTimeTest extends CalendricsTestCase
{
    // -------------------------------------------------------------------------
    // Constructor & readonly properties
    // -------------------------------------------------------------------------

    public function testConstructorSetsAllFields(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 45, 30, 123, 456, 789);

        static::assertSame(2020, $dt->year);
        static::assertSame(6, $dt->month);
        static::assertSame(15, $dt->day);
        static::assertSame(13, $dt->hour);
        static::assertSame(45, $dt->minute);
        static::assertSame(30, $dt->second);
        static::assertSame(123, $dt->millisecond);
        static::assertSame(456, $dt->microsecond);
        static::assertSame(789, $dt->nanosecond);
    }

    public function testConstructorDefaultsTimeToMidnight(): void
    {
        $dt = new PlainDateTime(2020, 6, 15);

        static::assertSame(0, $dt->hour);
        static::assertSame(0, $dt->minute);
        static::assertSame(0, $dt->second);
        static::assertSame(0, $dt->millisecond);
        static::assertSame(0, $dt->microsecond);
        static::assertSame(0, $dt->nanosecond);
    }

    public function testConstructorPartialTimeFields(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 10, 30);

        static::assertSame(10, $dt->hour);
        static::assertSame(30, $dt->minute);
        static::assertSame(0, $dt->second);
    }

    public function testConstructorRejectsInvalidDay(): void
    {
        $this->expectException(RangeError::class);
        new PlainDateTime(2020, 2, 30);
    }

    // -------------------------------------------------------------------------
    // Virtual properties
    // -------------------------------------------------------------------------

    public function testCalendarIsIso8601(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 12, 0);

        static::assertSame(Calendar::Iso8601, $dt->calendar);
    }

    public function testMonthCode(): void
    {
        static::assertSame('M01', new PlainDateTime(2020, 1, 1)->monthCode);
        static::assertSame('M06', new PlainDateTime(2020, 6, 15)->monthCode);
        static::assertSame('M12', new PlainDateTime(2020, 12, 31)->monthCode);
    }

    public function testDayOfWeek(): void
    {
        // 2020-06-15 is a Monday (1)
        static::assertSame(1, new PlainDateTime(2020, 6, 15)->dayOfWeek);
        // 2020-06-21 is a Sunday (7)
        static::assertSame(7, new PlainDateTime(2020, 6, 21)->dayOfWeek);
    }

    public function testDayOfYear(): void
    {
        static::assertSame(1, new PlainDateTime(2020, 1, 1)->dayOfYear);
        // Dec 31 of a leap year is day 366
        static::assertSame(366, new PlainDateTime(2020, 12, 31)->dayOfYear);
    }

    public function testWeekOfYear(): void
    {
        static::assertSame(1, new PlainDateTime(2020, 1, 1)->weekOfYear);
    }

    public function testYearOfWeek(): void
    {
        static::assertSame(2020, new PlainDateTime(2020, 1, 1)->yearOfWeek);
        // 2024-12-30 (Monday) is in ISO week 1 of 2025
        static::assertSame(2025, new PlainDateTime(2024, 12, 30)->yearOfWeek);
    }

    public function testDaysInMonth(): void
    {
        static::assertSame(31, new PlainDateTime(2020, 1, 1)->daysInMonth);
        static::assertSame(29, new PlainDateTime(2020, 2, 1)->daysInMonth);
        static::assertSame(28, new PlainDateTime(2019, 2, 1)->daysInMonth);
    }

    public function testDaysInYear(): void
    {
        static::assertSame(366, new PlainDateTime(2020, 1, 1)->daysInYear);
        static::assertSame(365, new PlainDateTime(2019, 1, 1)->daysInYear);
    }

    public function testDaysInWeek(): void
    {
        static::assertSame(7, new PlainDateTime(2020, 1, 1)->daysInWeek);
    }

    public function testMonthsInYear(): void
    {
        static::assertSame(12, new PlainDateTime(2020, 1, 1)->monthsInYear);
    }

    public function testInLeapYear(): void
    {
        static::assertTrue(new PlainDateTime(2020, 1, 1)->inLeapYear);
        static::assertFalse(new PlainDateTime(2019, 1, 1)->inLeapYear);
    }

    // -------------------------------------------------------------------------
    // parse
    // -------------------------------------------------------------------------

    public function testParseBasicDateTime(): void
    {
        $dt = PlainDateTime::parse('2020-06-15T13:45:30');

        static::assertSame(2020, $dt->year);
        static::assertSame(6, $dt->month);
        static::assertSame(15, $dt->day);
        static::assertSame(13, $dt->hour);
        static::assertSame(45, $dt->minute);
        static::assertSame(30, $dt->second);
    }

    public function testParseWithFractionalSeconds(): void
    {
        $dt = PlainDateTime::parse('2020-06-15T13:45:30.123456789');

        static::assertSame(123, $dt->millisecond);
        static::assertSame(456, $dt->microsecond);
        static::assertSame(789, $dt->nanosecond);
    }

    public function testParseDateOnly(): void
    {
        $dt = PlainDateTime::parse('2020-06-15');

        static::assertSame(2020, $dt->year);
        static::assertSame(6, $dt->month);
        static::assertSame(15, $dt->day);
        static::assertSame(0, $dt->hour);
    }

    public function testParseInvalidStringThrows(): void
    {
        $this->expectException(RangeError::class);
        PlainDateTime::parse('not-a-datetime');
    }

    public function testParseNegativeYear(): void
    {
        $dt = PlainDateTime::parse('-001000-01-01T00:00');

        static::assertSame(-1000, $dt->year);
    }

    public function testParseExtendedYear(): void
    {
        $dt = PlainDateTime::parse('+010000-01-01T00:00');

        static::assertSame(10_000, $dt->year);
    }

    // -------------------------------------------------------------------------
    // compare
    // -------------------------------------------------------------------------

    public function testCompareSame(): void
    {
        $a = new PlainDateTime(2020, 6, 15, 12, 0);
        $b = new PlainDateTime(2020, 6, 15, 12, 0);

        static::assertSame(0, PlainDateTime::compare($a, $b));
    }

    public function testCompareEarlierVsLater(): void
    {
        $a = new PlainDateTime(2020, 1, 1, 0, 0);
        $b = new PlainDateTime(2020, 12, 31, 23, 59);

        static::assertLessThan(0, PlainDateTime::compare($a, $b));
        static::assertGreaterThan(0, PlainDateTime::compare($b, $a));
    }

    public function testCompareByTime(): void
    {
        $a = new PlainDateTime(2020, 6, 15, 10, 0);
        $b = new PlainDateTime(2020, 6, 15, 14, 0);

        static::assertLessThan(0, PlainDateTime::compare($a, $b));
    }

    public function testCompareBySubSeconds(): void
    {
        $a = new PlainDateTime(2020, 6, 15, 12, 0, 0, 0, 0, 0);
        $b = new PlainDateTime(2020, 6, 15, 12, 0, 0, 0, 0, 1);

        static::assertLessThan(0, PlainDateTime::compare($a, $b));
    }

    // -------------------------------------------------------------------------
    // with
    // -------------------------------------------------------------------------

    public function testWithYear(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 12, 30);
        $result = $dt->with(year: 2021);

        static::assertSame(2021, $result->year);
        static::assertSame(6, $result->month);
        static::assertSame(15, $result->day);
        static::assertSame(12, $result->hour);
        static::assertSame(30, $result->minute);
    }

    public function testWithMonth(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 12, 0);
        $result = $dt->with(month: 1);

        static::assertSame(1, $result->month);
        static::assertSame(15, $result->day);
    }

    public function testWithDay(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 12, 0);
        $result = $dt->with(day: 1);

        static::assertSame(1, $result->day);
    }

    public function testWithHour(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 12, 0);
        $result = $dt->with(hour: 23);

        static::assertSame(23, $result->hour);
    }

    public function testWithMinute(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 12, 0);
        $result = $dt->with(minute: 59);

        static::assertSame(59, $result->minute);
    }

    public function testWithConstrainsDay(): void
    {
        // Jan 31 -> Feb: day constrained to 29 in 2020 (leap year)
        $dt = new PlainDateTime(2020, 1, 31, 12, 0);
        $result = $dt->with(month: 2);

        static::assertSame(29, $result->day);
    }

    public function testWithRejectOverflow(): void
    {
        $dt = new PlainDateTime(2020, 1, 31, 12, 0);

        $this->expectException(RangeError::class);
        $dt->with(month: 2, overflow: Overflow::Reject);
    }

    public function testWithReturnsNewInstance(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 12, 0);
        $result = $dt->with(year: 2021);

        static::assertNotSame($dt, $result);
        static::assertSame(2020, $dt->year);
    }

    public function testWithMultipleFields(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 12, 30, 45);
        $result = $dt->with(year: 2021, month: 3, hour: 8, second: 0);

        static::assertSame(2021, $result->year);
        static::assertSame(3, $result->month);
        static::assertSame(15, $result->day);
        static::assertSame(8, $result->hour);
        static::assertSame(30, $result->minute);
        static::assertSame(0, $result->second);
    }

    // -------------------------------------------------------------------------
    // add / subtract
    // -------------------------------------------------------------------------

    public function testAddDays(): void
    {
        $dt = new PlainDateTime(2020, 1, 1, 12, 0);
        $result = $dt->add(new Duration(days: 10));

        static::assertSame(2020, $result->year);
        static::assertSame(1, $result->month);
        static::assertSame(11, $result->day);
        static::assertSame(12, $result->hour);
    }

    public function testAddMonths(): void
    {
        $dt = new PlainDateTime(2020, 1, 31, 12, 0);
        $result = $dt->add(new Duration(months: 1));

        static::assertSame(2, $result->month);
        // Day constrained from 31 to 29 (Feb 2020 is leap)
        static::assertSame(29, $result->day);
    }

    public function testAddHours(): void
    {
        $dt = new PlainDateTime(2020, 1, 1, 23, 0);
        $result = $dt->add(new Duration(hours: 2));

        static::assertSame(2, $result->day);
        static::assertSame(1, $result->hour);
    }

    public function testAddMixed(): void
    {
        $dt = new PlainDateTime(2020, 1, 1, 10, 30);
        $result = $dt->add(new Duration(years: 1, months: 2, days: 3, hours: 4, minutes: 5));

        static::assertSame(2021, $result->year);
        static::assertSame(3, $result->month);
        static::assertSame(4, $result->day);
        static::assertSame(14, $result->hour);
        static::assertSame(35, $result->minute);
    }

    public function testSubtractDays(): void
    {
        $dt = new PlainDateTime(2020, 1, 11, 12, 0);
        $result = $dt->subtract(new Duration(days: 10));

        static::assertSame(1, $result->day);
        static::assertSame(12, $result->hour);
    }

    public function testSubtractAcrossDayBoundary(): void
    {
        $dt = new PlainDateTime(2020, 3, 1, 2, 0);
        $result = $dt->subtract(new Duration(hours: 3));

        static::assertSame(2, $result->month);
        static::assertSame(29, $result->day);
        static::assertSame(23, $result->hour);
    }

    public function testAddDoesNotMutateOriginal(): void
    {
        $dt = new PlainDateTime(2020, 1, 1, 12, 0);
        $dt->add(new Duration(days: 10));

        static::assertSame(1, $dt->day);
    }

    // -------------------------------------------------------------------------
    // since / until
    // -------------------------------------------------------------------------

    public function testSinceDays(): void
    {
        $a = new PlainDateTime(2020, 1, 1, 0, 0);
        $b = new PlainDateTime(2020, 1, 11, 0, 0);

        $dur = $b->since($a);

        static::assertSame(10, $dur->days);
    }

    public function testUntilDays(): void
    {
        $a = new PlainDateTime(2020, 1, 1, 0, 0);
        $b = new PlainDateTime(2020, 1, 11, 0, 0);

        $dur = $a->until($b);

        static::assertSame(10, $dur->days);
    }

    public function testSinceWithTimeComponent(): void
    {
        $a = new PlainDateTime(2020, 1, 1, 10, 0);
        $b = new PlainDateTime(2020, 1, 1, 14, 30);

        $dur = $b->since($a);

        static::assertSame(0, $dur->days);
        static::assertSame(4, $dur->hours);
        static::assertSame(30, $dur->minutes);
    }

    public function testUntilNegative(): void
    {
        $a = new PlainDateTime(2020, 1, 11, 0, 0);
        $b = new PlainDateTime(2020, 1, 1, 0, 0);

        $dur = $a->until($b);

        static::assertSame(-10, $dur->days);
    }

    public function testSinceWithLargestUnitYear(): void
    {
        $a = new PlainDateTime(2018, 6, 15, 10, 0);
        $b = new PlainDateTime(2020, 9, 20, 14, 30);

        $dur = $b->since($a, largestUnit: Unit::Year);

        static::assertSame(2, $dur->years);
        static::assertSame(3, $dur->months);
        static::assertSame(5, $dur->days);
        static::assertSame(4, $dur->hours);
        static::assertSame(30, $dur->minutes);
    }

    public function testUntilWithSmallestUnit(): void
    {
        $a = new PlainDateTime(2020, 1, 1, 10, 0, 0);
        $b = new PlainDateTime(2020, 1, 1, 10, 30, 45);

        $dur = $a->until($b, smallestUnit: Unit::Minute);

        static::assertSame(30, $dur->minutes);
        static::assertSame(0, $dur->seconds);
    }

    public function testUntilWithRoundingIncrement(): void
    {
        $a = new PlainDateTime(2020, 1, 1, 0, 0);
        $b = new PlainDateTime(2020, 1, 8, 0, 0);

        $dur = $a->until($b, smallestUnit: Unit::Day, roundingIncrement: 5);

        // 7 days rounded to nearest 5 with trunc = 5
        static::assertSame(5, $dur->days);
    }

    // -------------------------------------------------------------------------
    // round
    // -------------------------------------------------------------------------

    public function testRoundToHour(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 45, 30);
        $rounded = $dt->round(Unit::Hour);

        static::assertSame(14, $rounded->hour);
        static::assertSame(0, $rounded->minute);
        static::assertSame(0, $rounded->second);
    }

    public function testRoundToMinute(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 45, 30);
        $rounded = $dt->round(Unit::Minute);

        static::assertSame(13, $rounded->hour);
        static::assertSame(46, $rounded->minute);
        static::assertSame(0, $rounded->second);
    }

    public function testRoundToSecond(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 45, 30, 600);
        $rounded = $dt->round(Unit::Second);

        static::assertSame(31, $rounded->second);
        static::assertSame(0, $rounded->millisecond);
    }

    public function testRoundWithTruncMode(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 45, 59, 999);
        $rounded = $dt->round(Unit::Second, RoundingMode::Trunc);

        static::assertSame(59, $rounded->second);
        static::assertSame(0, $rounded->millisecond);
    }

    public function testRoundWithIncrement(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 47);
        $rounded = $dt->round(Unit::Minute, RoundingMode::HalfExpand, 15);

        static::assertSame(13, $rounded->hour);
        static::assertSame(45, $rounded->minute);
    }

    public function testRoundToDay(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 0);
        $rounded = $dt->round(Unit::Day);

        static::assertSame(16, $rounded->day);
        static::assertSame(0, $rounded->hour);
    }

    // -------------------------------------------------------------------------
    // equals
    // -------------------------------------------------------------------------

    public function testEqualsTrue(): void
    {
        $a = new PlainDateTime(2020, 6, 15, 13, 45, 30, 123, 456, 789);
        $b = new PlainDateTime(2020, 6, 15, 13, 45, 30, 123, 456, 789);

        static::assertTrue($a->equals($b));
    }

    public function testEqualsFalse(): void
    {
        $a = new PlainDateTime(2020, 6, 15, 13, 45, 30);
        $b = new PlainDateTime(2020, 6, 15, 13, 45, 31);

        static::assertFalse($a->equals($b));
    }

    public function testEqualsDifferentDate(): void
    {
        $a = new PlainDateTime(2020, 6, 15, 12, 0);
        $b = new PlainDateTime(2020, 6, 16, 12, 0);

        static::assertFalse($a->equals($b));
    }

    // -------------------------------------------------------------------------
    // toString
    // -------------------------------------------------------------------------

    public function testToStringDefault(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 45, 30);

        static::assertSame('2020-06-15T13:45:30', $dt->toString());
    }

    public function testToStringWithSubSeconds(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 45, 30, 123, 456, 789);

        static::assertSame('2020-06-15T13:45:30.123456789', $dt->toString());
    }

    public function testToStringCalendarAlways(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 12, 0);

        static::assertSame('2020-06-15T12:00:00[u-ca=iso8601]', $dt->toString(CalendarDisplay::Always));
    }

    public function testToStringCalendarNever(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 12, 0);

        static::assertSame('2020-06-15T12:00:00', $dt->toString(CalendarDisplay::Never));
    }

    public function testToStringCalendarCritical(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 12, 0);

        static::assertSame('2020-06-15T12:00:00[!u-ca=iso8601]', $dt->toString(CalendarDisplay::Critical));
    }

    public function testToStringWithFractionalSecondDigits(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 45, 30, 100);

        static::assertSame('2020-06-15T13:45:30.100', $dt->toString(fractionalSecondDigits: 3));
    }

    public function testToStringWithFractionalSecondDigitsZero(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 45, 30, 500);

        static::assertSame('2020-06-15T13:45:30', $dt->toString(fractionalSecondDigits: 0));
    }

    public function testToStringWithSmallestUnit(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 45, 30, 123);

        static::assertSame('2020-06-15T13:45', $dt->toString(smallestUnit: Unit::Minute));
    }

    public function testToStringWithRoundingMode(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 45, 30, 600);

        static::assertSame('2020-06-15T13:45:31', $dt->toString(
            fractionalSecondDigits: 0,
            roundingMode: RoundingMode::Ceil,
        ));
    }

    public function testToStringNegativeYear(): void
    {
        $dt = new PlainDateTime(-1000, 1, 1, 0, 0);

        static::assertSame('-001000-01-01T00:00:00', $dt->toString());
    }

    public function testToStringExtendedYear(): void
    {
        $dt = new PlainDateTime(10_000, 1, 1, 0, 0);

        static::assertSame('+010000-01-01T00:00:00', $dt->toString());
    }

    // -------------------------------------------------------------------------
    // __toString / jsonSerialize
    // -------------------------------------------------------------------------

    public function testMagicToString(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 45, 30);

        static::assertSame('2020-06-15T13:45:30', (string) $dt);
    }

    public function testJsonSerialize(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 45, 30);

        static::assertSame('"2020-06-15T13:45:30"', json_encode($dt));
    }

    public function testJsonSerializeWithSubSeconds(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 45, 30, 123, 456, 789);

        static::assertSame('"2020-06-15T13:45:30.123456789"', json_encode($dt));
    }

    // -------------------------------------------------------------------------
    // Conversion methods
    // -------------------------------------------------------------------------

    public function testToPlainDate(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 45, 30);
        $date = $dt->toPlainDate();

        static::assertSame(2020, $date->year);
        static::assertSame(6, $date->month);
        static::assertSame(15, $date->day);
    }

    public function testToPlainTime(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 45, 30, 123, 456, 789);
        $time = $dt->toPlainTime();

        static::assertSame(13, $time->hour);
        static::assertSame(45, $time->minute);
        static::assertSame(30, $time->second);
        static::assertSame(123, $time->millisecond);
        static::assertSame(456, $time->microsecond);
        static::assertSame(789, $time->nanosecond);
    }

    public function testWithPlainTimeReplacesTime(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 45, 30);
        $result = $dt->withPlainTime(new PlainTime(8, 15));

        static::assertSame(2020, $result->year);
        static::assertSame(6, $result->month);
        static::assertSame(15, $result->day);
        static::assertSame(8, $result->hour);
        static::assertSame(15, $result->minute);
        static::assertSame(0, $result->second);
    }

    public function testWithPlainTimeNullResetsMidnight(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 45, 30);
        $result = $dt->withPlainTime();

        static::assertSame(2020, $result->year);
        static::assertSame(6, $result->month);
        static::assertSame(15, $result->day);
        static::assertSame(0, $result->hour);
        static::assertSame(0, $result->minute);
        static::assertSame(0, $result->second);
    }

    // -------------------------------------------------------------------------
    // toSpec / fromSpec
    // -------------------------------------------------------------------------

    public function testToSpecReturnsSpecInstance(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 45, 30, 123, 456, 789);
        $spec = $dt->toSpec();

        static::assertSame(2020, $spec->year);
        static::assertSame(6, $spec->month);
        static::assertSame(15, $spec->day);
        static::assertSame(13, $spec->hour);
        static::assertSame(45, $spec->minute);
        static::assertSame(30, $spec->second);
        static::assertSame(123, $spec->millisecond);
        static::assertSame(456, $spec->microsecond);
        static::assertSame(789, $spec->nanosecond);
    }

    public function testFromSpecCreatesInstance(): void
    {
        $spec = new \Calendrics\Spec\PlainDateTime(2020, 6, 15, 13, 45, 30, 123, 456, 789);
        $dt = PlainDateTime::fromSpec($spec);

        static::assertSame(2020, $dt->year);
        static::assertSame(6, $dt->month);
        static::assertSame(15, $dt->day);
        static::assertSame(13, $dt->hour);
        static::assertSame(45, $dt->minute);
        static::assertSame(30, $dt->second);
        static::assertSame(123, $dt->millisecond);
        static::assertSame(456, $dt->microsecond);
        static::assertSame(789, $dt->nanosecond);
    }

    public function testToSpecRoundTrip(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 45, 30, 123, 456, 789);
        $restored = PlainDateTime::fromSpec($dt->toSpec());

        static::assertTrue($dt->equals($restored));
    }

    // -------------------------------------------------------------------------
    // __debugInfo
    // -------------------------------------------------------------------------

    public function testDebugInfo(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 45, 30, 123, 456, 789);
        $info = $dt->__debugInfo();

        static::assertSame(2020, $info['year']);
        static::assertSame(6, $info['month']);
        static::assertSame(15, $info['day']);
        static::assertSame(13, $info['hour']);
        static::assertSame(45, $info['minute']);
        static::assertSame(30, $info['second']);
        static::assertSame(123, $info['millisecond']);
        static::assertSame(456, $info['microsecond']);
        static::assertSame(789, $info['nanosecond']);
        static::assertSame(Calendar::Iso8601, $info['calendar']);
        static::assertSame('2020-06-15T13:45:30.123456789', $info['iso']);
    }

    public function testDebugInfoMidnight(): void
    {
        $dt = new PlainDateTime(2020, 1, 1);
        $info = $dt->__debugInfo();

        static::assertSame(0, $info['hour']);
        static::assertSame(0, $info['minute']);
        static::assertSame(0, $info['second']);
        static::assertSame('2020-01-01T00:00:00', $info['iso']);
    }

    // -------------------------------------------------------------------------
    // Mutation coverage: with() forwards millisecond, microsecond, nanosecond
    // -------------------------------------------------------------------------

    public function testWithMillisecond(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 12, 0, 0, 0, 0, 0);
        $result = $dt->with(millisecond: 500);

        static::assertSame(500, $result->millisecond);
        static::assertSame(0, $result->microsecond);
        static::assertSame(0, $result->nanosecond);
    }

    public function testWithMicrosecond(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 12, 0, 0, 0, 0, 0);
        $result = $dt->with(microsecond: 300);

        static::assertSame(0, $result->millisecond);
        static::assertSame(300, $result->microsecond);
        static::assertSame(0, $result->nanosecond);
    }

    public function testWithNanosecond(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 12, 0, 0, 0, 0, 0);
        $result = $dt->with(nanosecond: 100);

        static::assertSame(0, $result->millisecond);
        static::assertSame(0, $result->microsecond);
        static::assertSame(100, $result->nanosecond);
    }

    // -------------------------------------------------------------------------
    // Mutation coverage: add/subtract forward overflow option
    // -------------------------------------------------------------------------

    public function testAddForwardsOverflowReject(): void
    {
        $dt = new PlainDateTime(2020, 1, 31, 12, 0);
        $this->expectException(RangeError::class);
        $dt->add(new Duration(months: 1), Overflow::Reject);
    }

    public function testSubtractForwardsOverflowReject(): void
    {
        $dt = new PlainDateTime(2020, 3, 31, 12, 0);
        $this->expectException(RangeError::class);
        $dt->subtract(new Duration(months: 1), Overflow::Reject);
    }

    // -------------------------------------------------------------------------
    // Mutation coverage: since() forwards all options
    // -------------------------------------------------------------------------

    public function testSinceForwardsSmallestUnit(): void
    {
        $a = new PlainDateTime(2020, 1, 1, 10, 0, 0);
        $b = new PlainDateTime(2020, 1, 1, 10, 30, 45);
        $dur = $b->since($a, smallestUnit: Unit::Minute);

        static::assertSame(30, $dur->minutes);
        static::assertSame(0, $dur->seconds);
    }

    public function testSinceForwardsRoundingMode(): void
    {
        $a = new PlainDateTime(2020, 1, 1, 10, 0, 0);
        $b = new PlainDateTime(2020, 1, 1, 10, 0, 29);

        $trunc = $b->since($a, smallestUnit: Unit::Minute, roundingMode: RoundingMode::Trunc);
        $ceil = $b->since($a, smallestUnit: Unit::Minute, roundingMode: RoundingMode::Ceil);

        static::assertSame(0, $trunc->minutes);
        static::assertSame(1, $ceil->minutes);
    }

    public function testSinceForwardsRoundingIncrement(): void
    {
        $a = new PlainDateTime(2020, 1, 1, 0, 0);
        $b = new PlainDateTime(2020, 1, 8, 0, 0);
        $dur = $b->since($a, smallestUnit: Unit::Day, roundingIncrement: 5);

        static::assertSame(5, $dur->days);
    }

    // -------------------------------------------------------------------------
    // Mutation coverage: until() forwards all options
    // -------------------------------------------------------------------------

    public function testUntilForwardsLargestUnit(): void
    {
        $a = new PlainDateTime(2020, 1, 1, 0, 0);
        $b = new PlainDateTime(2020, 4, 1, 0, 0);
        $dur = $a->until($b, largestUnit: Unit::Month);

        static::assertSame(3, $dur->months);
        static::assertSame(0, $dur->days);
    }

    public function testUntilForwardsSmallestUnit(): void
    {
        $a = new PlainDateTime(2020, 1, 1, 10, 0, 0);
        $b = new PlainDateTime(2020, 1, 1, 10, 30, 45);
        $dur = $a->until($b, smallestUnit: Unit::Minute);

        static::assertSame(30, $dur->minutes);
        static::assertSame(0, $dur->seconds);
    }

    public function testUntilForwardsRoundingMode(): void
    {
        $a = new PlainDateTime(2020, 1, 1, 10, 0, 0);
        $b = new PlainDateTime(2020, 1, 1, 10, 0, 29);

        $trunc = $a->until($b, smallestUnit: Unit::Minute, roundingMode: RoundingMode::Trunc);
        $ceil = $a->until($b, smallestUnit: Unit::Minute, roundingMode: RoundingMode::Ceil);

        static::assertSame(0, $trunc->minutes);
        static::assertSame(1, $ceil->minutes);
    }

    // -------------------------------------------------------------------------
    // Calendar enum in constructor
    // -------------------------------------------------------------------------

    public function testConstructorAcceptsCalendarEnum(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 12, 0, 0, 0, 0, 0, Calendar::Gregory);

        static::assertSame(Calendar::Gregory, $dt->calendar);
    }

    public function testConstructorDefaultsToIso8601Calendar(): void
    {
        $dt = new PlainDateTime(2020, 6, 15);

        static::assertSame(Calendar::Iso8601, $dt->calendar);
    }

    // -------------------------------------------------------------------------
    // era / eraYear virtual properties
    // -------------------------------------------------------------------------

    public function testEraIsNullForIsoCalendar(): void
    {
        $dt = new PlainDateTime(2020, 6, 15);

        static::assertNull($dt->era);
        static::assertNull($dt->eraYear);
    }

    public function testEraForGregoryCalendar(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 0, 0, 0, 0, 0, 0, Calendar::Gregory);

        static::assertSame('ce', $dt->era);
        static::assertSame(2020, $dt->eraYear);
    }

    // -------------------------------------------------------------------------
    // fromFields() static factory
    // -------------------------------------------------------------------------

    public function testFromPropertyBag(): void
    {
        $dt = PlainDateTime::fromFields(year: 2020, month: 6, day: 15);

        static::assertSame(2020, $dt->year);
        static::assertSame(6, $dt->month);
        static::assertSame(15, $dt->day);
        static::assertSame(0, $dt->hour);
    }

    public function testFromFieldsForwardsCalendar(): void
    {
        $dt = PlainDateTime::fromFields(year: 2024, month: 1, day: 15, calendar: Calendar::Gregory);

        static::assertSame(Calendar::Gregory, $dt->calendar);
    }

    public function testFromFieldsForwardsOverflowReject(): void
    {
        $this->expectException(RangeError::class);

        PlainDateTime::fromFields(year: 2020, month: 2, day: 30, overflow: Overflow::Reject);
    }

    public function testFromFieldsForwardsAllTimeFields(): void
    {
        $dt = PlainDateTime::fromFields(
            year: 2020,
            month: 6,
            day: 15,
            hour: 13,
            minute: 45,
            second: 30,
            millisecond: 123,
            microsecond: 456,
            nanosecond: 789,
        );

        static::assertSame(13, $dt->hour);
        static::assertSame(45, $dt->minute);
        static::assertSame(30, $dt->second);
        static::assertSame(123, $dt->millisecond);
        static::assertSame(456, $dt->microsecond);
        static::assertSame(789, $dt->nanosecond);
    }

    // -------------------------------------------------------------------------
    // withCalendar()
    // -------------------------------------------------------------------------

    public function testWithCalendar(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 12, 30);
        $gregory = $dt->withCalendar(Calendar::Gregory);

        static::assertSame(Calendar::Gregory, $gregory->calendar);
        static::assertSame(12, $gregory->hour);
        static::assertSame(30, $gregory->minute);
    }

    public function testWithCalendarReturnsNewInstance(): void
    {
        $dt = new PlainDateTime(2020, 6, 15);
        $result = $dt->withCalendar(Calendar::Gregory);

        static::assertNotSame($dt, $result);
    }

    // -------------------------------------------------------------------------
    // with() calendar-specific fields
    // -------------------------------------------------------------------------

    public function testWithMonthCode(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 12, 30);
        $result = $dt->with(monthCode: 'M03');

        static::assertSame(3, $result->month);
        static::assertSame(15, $result->day);
        static::assertSame(12, $result->hour);
    }

    public function testWithEraAndEraYear(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 12, 30, 0, 0, 0, 0, Calendar::Gregory);
        $result = $dt->with(era: 'ce', eraYear: 2021);

        static::assertSame(2021, $result->year);
        static::assertSame(6, $result->month);
        static::assertSame(12, $result->hour);
    }

    public function testFromFieldsWithEraAndEraYear(): void
    {
        $dt = PlainDateTime::fromFields(
            year: 2020,
            month: 6,
            day: 15,
            hour: 12,
            minute: 30,
            calendar: Calendar::Gregory,
            era: 'ce',
            eraYear: 2020,
        );

        static::assertSame(2020, $dt->year);
        static::assertSame('ce', $dt->era);
        static::assertSame(2020, $dt->eraYear);
    }

    // -------------------------------------------------------------------------
    // toZonedDateTime
    // -------------------------------------------------------------------------

    public function testToZonedDateTime(): void
    {
        $dt = new PlainDateTime(2020, 6, 15, 13, 45, 30);
        $zdt = $dt->toZonedDateTime('UTC');

        static::assertSame(2020, $zdt->year);
        static::assertSame(6, $zdt->month);
        static::assertSame(15, $zdt->day);
        static::assertSame(13, $zdt->hour);
        static::assertSame(45, $zdt->minute);
        static::assertSame(30, $zdt->second);
        static::assertSame('UTC', $zdt->timeZoneId);
    }

    public function testToZonedDateTimeForwardsDisambiguation(): void
    {
        // 2020-11-01 01:30 is ambiguous in America/New_York (fall-back DST)
        $dt = new PlainDateTime(2020, 11, 1, 1, 30);

        $earlier = $dt->toZonedDateTime('America/New_York', \Calendrics\Disambiguation::Earlier);
        $later = $dt->toZonedDateTime('America/New_York', \Calendrics\Disambiguation::Later);

        static::assertSame('-04:00', $earlier->offset);
        static::assertSame('-05:00', $later->offset);
    }

    // -------------------------------------------------------------------------
    // fromDateTime()
    // -------------------------------------------------------------------------

    public function testFromDateTimeReadsCivilFieldsFromZone(): void
    {
        // Same instant, different zones — fromDateTime must take Tokyo's local fields,
        // not UTC's. 2020-06-15T12:30:45 UTC = 2020-06-15T21:30:45 Asia/Tokyo.
        $dt = new \DateTimeImmutable('2020-06-15T21:30:45.123456', new \DateTimeZone('Asia/Tokyo'));
        $pdt = PlainDateTime::fromDateTime($dt);

        static::assertSame(2020, $pdt->year);
        static::assertSame(6, $pdt->month);
        static::assertSame(15, $pdt->day);
        static::assertSame(21, $pdt->hour); // Tokyo, not UTC
        static::assertSame(30, $pdt->minute);
        static::assertSame(45, $pdt->second);
        static::assertSame(123, $pdt->millisecond);
        static::assertSame(456, $pdt->microsecond);
        static::assertSame(0, $pdt->nanosecond);
    }

    public function testFromDateTimeDefaultsToIsoCalendar(): void
    {
        $dt = new \DateTimeImmutable('2020-06-15T12:30:45', new \DateTimeZone('UTC'));
        $pdt = PlainDateTime::fromDateTime($dt);

        static::assertSame(Calendar::Iso8601, $pdt->calendar);
    }

    public function testFromDateTimeAcceptsExplicitCalendar(): void
    {
        $dt = new \DateTimeImmutable('2024-06-15T12:30:45', new \DateTimeZone('UTC'));
        $pdt = PlainDateTime::fromDateTime($dt, Calendar::Hebrew);

        static::assertSame(Calendar::Hebrew, $pdt->calendar);
        // The Hebrew projection of the ISO 2024-06-15 date has a non-Gregorian year.
        static::assertNotSame(2024, $pdt->year);
        // Spec layer preserves the ISO date used to build it.
        static::assertSame(2024, $pdt->toSpec()->isoYear);
        static::assertSame(6, $pdt->toSpec()->isoMonth);
        static::assertSame(15, $pdt->toSpec()->isoDay);
    }

    public function testFromDateTimeZeroMicroseconds(): void
    {
        $dt = new \DateTimeImmutable('2020-06-15T12:30:45', new \DateTimeZone('UTC'));
        $pdt = PlainDateTime::fromDateTime($dt);

        static::assertSame(0, $pdt->millisecond);
        static::assertSame(0, $pdt->microsecond);
        static::assertSame(0, $pdt->nanosecond);
    }

    public function testFromDateTimeMaxMicrosecondsSplitsCorrectly(): void
    {
        // u=999999 — pins the divisor at exactly 1000 (intdiv(999999, 999)=1001, not 999).
        $dt = new \DateTimeImmutable('2020-06-15T12:30:45.999999', new \DateTimeZone('UTC'));
        $pdt = PlainDateTime::fromDateTime($dt);

        static::assertSame(999, $pdt->millisecond);
        static::assertSame(999, $pdt->microsecond);
    }

    public function testFromDateTimeMidRangeMicrosecondsSplitCorrectly(): void
    {
        // u=500000 — pins divisor at <= 1000 (intdiv(500000, 1001)=499, not 500).
        $dt = new \DateTimeImmutable('2020-06-15T12:30:45.500000', new \DateTimeZone('UTC'));
        $pdt = PlainDateTime::fromDateTime($dt);

        static::assertSame(500, $pdt->millisecond);
        static::assertSame(0, $pdt->microsecond);
    }
}
