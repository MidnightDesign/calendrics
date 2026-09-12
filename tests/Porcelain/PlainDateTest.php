<?php

declare(strict_types=1);

namespace Calendrics\Tests\Porcelain;

use Calendrics\Calendar;
use Calendrics\CalendarDisplay;
use Calendrics\Duration;
use Calendrics\Exception\RangeError;
use Calendrics\Overflow;
use Calendrics\PlainDate;
use Calendrics\RoundingMode;
use Calendrics\Unit;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PlainDateTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Constructor & readonly properties
    // -------------------------------------------------------------------------

    public function testConstructorSetsReadonlyProperties(): void
    {
        $pd = new PlainDate(2020, 6, 15);

        static::assertSame(2020, $pd->year);
        static::assertSame(6, $pd->month);
        static::assertSame(15, $pd->day);
    }

    public function testConstructorRejectsInvalidDay(): void
    {
        $this->expectException(RangeError::class);
        new PlainDate(2020, 2, 30);
    }

    // -------------------------------------------------------------------------
    // Virtual properties
    // -------------------------------------------------------------------------

    public function testCalendarIsIso8601(): void
    {
        $pd = new PlainDate(2020, 6, 15);

        static::assertSame(Calendar::Iso8601, $pd->calendar);
    }

    public function testDayOfWeek(): void
    {
        // 2020-06-15 is a Monday (1)
        static::assertSame(1, new PlainDate(2020, 6, 15)->dayOfWeek);
        // 2020-06-21 is a Sunday (7)
        static::assertSame(7, new PlainDate(2020, 6, 21)->dayOfWeek);
    }

    public function testDayOfYear(): void
    {
        // Jan 1 is day 1
        static::assertSame(1, new PlainDate(2020, 1, 1)->dayOfYear);
        // Dec 31 of a leap year is day 366
        static::assertSame(366, new PlainDate(2020, 12, 31)->dayOfYear);
    }

    public function testWeekOfYear(): void
    {
        // 2020-01-01 is in week 1
        static::assertSame(1, new PlainDate(2020, 1, 1)->weekOfYear);
    }

    public function testYearOfWeek(): void
    {
        // 2020-01-01 is in week-year 2020
        static::assertSame(2020, new PlainDate(2020, 1, 1)->yearOfWeek);
        // 2024-12-30 (Monday) is in ISO week 1 of 2025
        static::assertSame(2025, new PlainDate(2024, 12, 30)->yearOfWeek);
    }

    public function testDaysInMonth(): void
    {
        static::assertSame(31, new PlainDate(2020, 1, 1)->daysInMonth);
        static::assertSame(29, new PlainDate(2020, 2, 1)->daysInMonth);
        static::assertSame(28, new PlainDate(2019, 2, 1)->daysInMonth);
    }

    public function testDaysInYear(): void
    {
        static::assertSame(366, new PlainDate(2020, 1, 1)->daysInYear);
        static::assertSame(365, new PlainDate(2019, 1, 1)->daysInYear);
    }

    public function testDaysInWeek(): void
    {
        static::assertSame(7, new PlainDate(2020, 1, 1)->daysInWeek);
    }

    public function testMonthsInYear(): void
    {
        static::assertSame(12, new PlainDate(2020, 1, 1)->monthsInYear);
    }

    public function testMonthCode(): void
    {
        static::assertSame('M01', new PlainDate(2020, 1, 1)->monthCode);
        static::assertSame('M06', new PlainDate(2020, 6, 15)->monthCode);
        static::assertSame('M12', new PlainDate(2020, 12, 31)->monthCode);
    }

    public function testInLeapYear(): void
    {
        static::assertTrue(new PlainDate(2020, 1, 1)->inLeapYear);
        static::assertFalse(new PlainDate(2019, 1, 1)->inLeapYear);
    }

    // -------------------------------------------------------------------------
    // parse
    // -------------------------------------------------------------------------

    public function testParseBasicDate(): void
    {
        $pd = PlainDate::parse('2020-06-15');

        static::assertSame(2020, $pd->year);
        static::assertSame(6, $pd->month);
        static::assertSame(15, $pd->day);
    }

    public function testParseWithTime(): void
    {
        $pd = PlainDate::parse('2020-06-15T12:30:00');

        static::assertSame(2020, $pd->year);
        static::assertSame(6, $pd->month);
        static::assertSame(15, $pd->day);
    }

    public function testParseInvalidStringThrows(): void
    {
        $this->expectException(RangeError::class);
        PlainDate::parse('not-a-date');
    }

    public function testParseNegativeYear(): void
    {
        $pd = PlainDate::parse('-001000-01-01');

        static::assertSame(-1000, $pd->year);
        static::assertSame(1, $pd->month);
        static::assertSame(1, $pd->day);
    }

    public function testParseExtendedYear(): void
    {
        $pd = PlainDate::parse('+010000-01-01');

        static::assertSame(10_000, $pd->year);
        static::assertSame(1, $pd->month);
        static::assertSame(1, $pd->day);
    }

    // -------------------------------------------------------------------------
    // compare
    // -------------------------------------------------------------------------

    public function testCompareSameDate(): void
    {
        $a = new PlainDate(2020, 6, 15);
        $b = new PlainDate(2020, 6, 15);

        static::assertSame(0, PlainDate::compare($a, $b));
    }

    public function testCompareEarlierVsLater(): void
    {
        $a = new PlainDate(2020, 1, 1);
        $b = new PlainDate(2020, 12, 31);

        static::assertLessThan(0, PlainDate::compare($a, $b));
        static::assertGreaterThan(0, PlainDate::compare($b, $a));
    }

    public function testCompareByYear(): void
    {
        $a = new PlainDate(2019, 12, 31);
        $b = new PlainDate(2020, 1, 1);

        static::assertLessThan(0, PlainDate::compare($a, $b));
    }

    // -------------------------------------------------------------------------
    // with
    // -------------------------------------------------------------------------

    public function testWithYear(): void
    {
        $pd = new PlainDate(2020, 6, 15);
        $result = $pd->with(year: 2021);

        static::assertSame(2021, $result->year);
        static::assertSame(6, $result->month);
        static::assertSame(15, $result->day);
    }

    public function testWithMonth(): void
    {
        $pd = new PlainDate(2020, 6, 15);
        $result = $pd->with(month: 1);

        static::assertSame(2020, $result->year);
        static::assertSame(1, $result->month);
        static::assertSame(15, $result->day);
    }

    public function testWithDay(): void
    {
        $pd = new PlainDate(2020, 6, 15);
        $result = $pd->with(day: 1);

        static::assertSame(2020, $result->year);
        static::assertSame(6, $result->month);
        static::assertSame(1, $result->day);
    }

    public function testWithConstrainsDay(): void
    {
        // Feb has 29 days in 2020; day 31 should constrain to 29.
        $pd = new PlainDate(2020, 1, 31);
        $result = $pd->with(month: 2);

        static::assertSame(29, $result->day);
    }

    public function testWithRejectOverflow(): void
    {
        $pd = new PlainDate(2020, 1, 31);

        $this->expectException(RangeError::class);
        $pd->with(month: 2, overflow: Overflow::Reject);
    }

    public function testWithReturnsNewInstance(): void
    {
        $pd = new PlainDate(2020, 6, 15);
        $result = $pd->with(year: 2021);

        static::assertNotSame($pd, $result);
    }

    // -------------------------------------------------------------------------
    // add / subtract
    // -------------------------------------------------------------------------

    public function testAddDays(): void
    {
        $pd = new PlainDate(2020, 1, 1);
        $result = $pd->add(new Duration(days: 10));

        static::assertSame(2020, $result->year);
        static::assertSame(1, $result->month);
        static::assertSame(11, $result->day);
    }

    public function testAddMonths(): void
    {
        $pd = new PlainDate(2020, 1, 31);
        $result = $pd->add(new Duration(months: 1));

        static::assertSame(2020, $result->year);
        static::assertSame(2, $result->month);
        // Day constrained from 31 to 29 (Feb 2020 is leap)
        static::assertSame(29, $result->day);
    }

    public function testAddYears(): void
    {
        $pd = new PlainDate(2020, 2, 29);
        $result = $pd->add(new Duration(years: 1));

        // 2021 is not a leap year, so Feb 29 constrains to Feb 28
        static::assertSame(2021, $result->year);
        static::assertSame(2, $result->month);
        static::assertSame(28, $result->day);
    }

    public function testSubtractDays(): void
    {
        $pd = new PlainDate(2020, 1, 11);
        $result = $pd->subtract(new Duration(days: 10));

        static::assertSame(2020, $result->year);
        static::assertSame(1, $result->month);
        static::assertSame(1, $result->day);
    }

    public function testSubtractAcrossMonthBoundary(): void
    {
        $pd = new PlainDate(2020, 3, 1);
        $result = $pd->subtract(new Duration(days: 1));

        static::assertSame(2020, $result->year);
        static::assertSame(2, $result->month);
        static::assertSame(29, $result->day);
    }

    // -------------------------------------------------------------------------
    // since / until
    // -------------------------------------------------------------------------

    public function testSinceDays(): void
    {
        $a = new PlainDate(2020, 1, 1);
        $b = new PlainDate(2020, 1, 11);

        $dur = $b->since($a);

        static::assertSame(10, $dur->days);
    }

    public function testUntilDays(): void
    {
        $a = new PlainDate(2020, 1, 1);
        $b = new PlainDate(2020, 1, 11);

        $dur = $a->until($b);

        static::assertSame(10, $dur->days);
    }

    public function testSinceNegative(): void
    {
        $a = new PlainDate(2020, 1, 11);
        $b = new PlainDate(2020, 1, 1);

        $dur = $b->since($a);

        static::assertSame(-10, $dur->days);
    }

    public function testUntilNegative(): void
    {
        $a = new PlainDate(2020, 1, 11);
        $b = new PlainDate(2020, 1, 1);

        $dur = $a->until($b);

        static::assertSame(-10, $dur->days);
    }

    public function testSinceWithLargestUnitYear(): void
    {
        $a = new PlainDate(2018, 6, 15);
        $b = new PlainDate(2020, 9, 20);

        $dur = $b->since($a, largestUnit: Unit::Year);

        static::assertSame(2, $dur->years);
        static::assertSame(3, $dur->months);
        static::assertSame(5, $dur->days);
    }

    public function testUntilWithLargestUnitMonth(): void
    {
        $a = new PlainDate(2020, 1, 1);
        $b = new PlainDate(2020, 4, 1);

        $dur = $a->until($b, largestUnit: Unit::Month);

        static::assertSame(3, $dur->months);
        static::assertSame(0, $dur->days);
    }

    public function testSinceWithRoundingIncrement(): void
    {
        $a = new PlainDate(2020, 1, 1);
        $b = new PlainDate(2020, 1, 8);

        $dur = $b->since($a, roundingIncrement: 5);

        // 7 days rounded to nearest 5 with trunc = 5
        static::assertSame(5, $dur->days);
    }

    // -------------------------------------------------------------------------
    // equals
    // -------------------------------------------------------------------------

    public function testEqualsSameDate(): void
    {
        $a = new PlainDate(2020, 6, 15);
        $b = new PlainDate(2020, 6, 15);

        static::assertTrue($a->equals($b));
    }

    public function testEqualsDifferentDate(): void
    {
        $a = new PlainDate(2020, 6, 15);
        $b = new PlainDate(2020, 6, 16);

        static::assertFalse($a->equals($b));
    }

    // -------------------------------------------------------------------------
    // toString
    // -------------------------------------------------------------------------

    public function testToStringDefault(): void
    {
        $pd = new PlainDate(2020, 6, 15);

        static::assertSame('2020-06-15', $pd->toString());
    }

    public function testToStringCalendarAlways(): void
    {
        $pd = new PlainDate(2020, 6, 15);

        static::assertSame('2020-06-15[u-ca=iso8601]', $pd->toString(CalendarDisplay::Always));
    }

    public function testToStringCalendarNever(): void
    {
        $pd = new PlainDate(2020, 6, 15);

        static::assertSame('2020-06-15', $pd->toString(CalendarDisplay::Never));
    }

    public function testToStringCalendarCritical(): void
    {
        $pd = new PlainDate(2020, 6, 15);

        static::assertSame('2020-06-15[!u-ca=iso8601]', $pd->toString(CalendarDisplay::Critical));
    }

    public function testToStringNegativeYear(): void
    {
        $pd = new PlainDate(-1000, 1, 1);

        static::assertSame('-001000-01-01', $pd->toString());
    }

    public function testToStringExtendedYear(): void
    {
        $pd = new PlainDate(10_000, 1, 1);

        static::assertSame('+010000-01-01', $pd->toString());
    }

    // -------------------------------------------------------------------------
    // __toString / jsonSerialize
    // -------------------------------------------------------------------------

    public function testMagicToString(): void
    {
        $pd = new PlainDate(2020, 6, 15);

        static::assertSame('2020-06-15', (string) $pd);
    }

    public function testJsonSerialize(): void
    {
        $pd = new PlainDate(2020, 6, 15);

        static::assertSame('"2020-06-15"', json_encode($pd));
    }

    // -------------------------------------------------------------------------
    // toSpec / fromSpec
    // -------------------------------------------------------------------------

    public function testToSpecReturnsSpecInstance(): void
    {
        $pd = new PlainDate(2020, 6, 15);
        $spec = $pd->toSpec();

        static::assertSame(2020, $spec->year);
        static::assertSame(6, $spec->month);
        static::assertSame(15, $spec->day);
    }

    public function testFromSpecCreatesInstance(): void
    {
        $spec = new \Calendrics\Spec\PlainDate(2020, 6, 15);
        $pd = PlainDate::fromSpec($spec);

        static::assertSame(2020, $pd->year);
        static::assertSame(6, $pd->month);
        static::assertSame(15, $pd->day);
    }

    public function testToSpecRoundTrip(): void
    {
        $pd = new PlainDate(2020, 6, 15);
        $restored = PlainDate::fromSpec($pd->toSpec());

        static::assertTrue($pd->equals($restored));
    }

    // -------------------------------------------------------------------------
    // __debugInfo
    // -------------------------------------------------------------------------

    public function testDebugInfo(): void
    {
        $pd = new PlainDate(2020, 6, 15);
        $info = $pd->__debugInfo();

        static::assertSame(2020, $info['year']);
        static::assertSame(6, $info['month']);
        static::assertSame(15, $info['day']);
        static::assertSame(Calendar::Iso8601, $info['calendar']);
        static::assertSame('2020-06-15', $info['iso']);
    }

    // -------------------------------------------------------------------------
    // Mutation coverage: add/subtract forward overflow option
    // -------------------------------------------------------------------------

    public function testAddForwardsOverflowReject(): void
    {
        $pd = new PlainDate(2020, 1, 31);
        $this->expectException(RangeError::class);
        $pd->add(new Duration(months: 1), Overflow::Reject);
    }

    public function testSubtractForwardsOverflowReject(): void
    {
        $pd = new PlainDate(2020, 3, 31);
        $this->expectException(RangeError::class);
        $pd->subtract(new Duration(months: 1), Overflow::Reject);
    }

    // -------------------------------------------------------------------------
    // Calendar enum in constructor
    // -------------------------------------------------------------------------

    public function testConstructorAcceptsCalendarEnum(): void
    {
        $pd = new PlainDate(2020, 6, 15, Calendar::Gregory);

        static::assertSame(Calendar::Gregory, $pd->calendar);
    }

    public function testConstructorDefaultsToIso8601Calendar(): void
    {
        $pd = new PlainDate(2020, 6, 15);

        static::assertSame(Calendar::Iso8601, $pd->calendar);
    }

    // -------------------------------------------------------------------------
    // era / eraYear virtual properties
    // -------------------------------------------------------------------------

    public function testEraIsNullForIsoCalendar(): void
    {
        $pd = new PlainDate(2020, 6, 15);

        static::assertNull($pd->era);
        static::assertNull($pd->eraYear);
    }

    public function testEraForGregoryCalendar(): void
    {
        $pd = new PlainDate(2020, 6, 15, Calendar::Gregory);

        static::assertSame('ce', $pd->era);
        static::assertSame(2020, $pd->eraYear);
    }

    // -------------------------------------------------------------------------
    // fromFields() static factory
    // -------------------------------------------------------------------------

    public function testFromPropertyBag(): void
    {
        $pd = PlainDate::fromFields(year: 2020, month: 6, day: 15);

        static::assertSame(2020, $pd->year);
        static::assertSame(6, $pd->month);
        static::assertSame(15, $pd->day);
    }

    public function testFromFieldsForwardsCalendar(): void
    {
        $pd = PlainDate::fromFields(year: 2024, month: 1, day: 15, calendar: Calendar::Gregory);

        static::assertSame(Calendar::Gregory, $pd->calendar);
    }

    public function testFromFieldsForwardsOverflowReject(): void
    {
        $this->expectException(RangeError::class);

        PlainDate::fromFields(year: 2020, month: 2, day: 30, overflow: Overflow::Reject);
    }

    // -------------------------------------------------------------------------
    // withCalendar()
    // -------------------------------------------------------------------------

    public function testWithCalendar(): void
    {
        $pd = new PlainDate(2020, 6, 15);
        $gregory = $pd->withCalendar(Calendar::Gregory);

        static::assertSame(Calendar::Gregory, $gregory->calendar);
        // Same ISO date, different calendar projection
        static::assertSame('2020-06-15', $gregory->toString(CalendarDisplay::Never));
    }

    public function testWithCalendarReturnsNewInstance(): void
    {
        $pd = new PlainDate(2020, 6, 15);
        $result = $pd->withCalendar(Calendar::Gregory);

        static::assertNotSame($pd, $result);
    }

    // -------------------------------------------------------------------------
    // with() calendar-specific fields
    // -------------------------------------------------------------------------

    public function testWithMonthCode(): void
    {
        $pd = new PlainDate(2020, 6, 15);
        $result = $pd->with(monthCode: 'M03');

        static::assertSame(3, $result->month);
        static::assertSame(15, $result->day);
    }

    public function testWithEraAndEraYear(): void
    {
        $pd = new PlainDate(2020, 6, 15, Calendar::Gregory);
        $result = $pd->with(era: 'ce', eraYear: 2021);

        static::assertSame(2021, $result->year);
        static::assertSame(6, $result->month);
        static::assertSame(15, $result->day);
    }

    public function testFromFieldsWithEraAndEraYear(): void
    {
        $pd = PlainDate::fromFields(
            year: 2020,
            month: 6,
            day: 15,
            calendar: Calendar::Gregory,
            era: 'ce',
            eraYear: 2020,
        );

        static::assertSame(2020, $pd->year);
        static::assertSame('ce', $pd->era);
        static::assertSame(2020, $pd->eraYear);
    }

    // -------------------------------------------------------------------------
    // Mutation coverage: since/until forward smallestUnit, roundingMode, roundingIncrement
    // -------------------------------------------------------------------------

    public function testSinceForwardsSmallestUnit(): void
    {
        $a = new PlainDate(2020, 1, 1);
        $b = new PlainDate(2020, 4, 15);
        $dur = $b->since($a, largestUnit: Unit::Month, smallestUnit: Unit::Month);

        static::assertSame(3, $dur->months);
        static::assertSame(0, $dur->days);
    }

    public function testSinceForwardsRoundingMode(): void
    {
        $a = new PlainDate(2020, 1, 1);
        $b = new PlainDate(2020, 1, 8);

        $trunc = $b->since($a, roundingIncrement: 5, roundingMode: RoundingMode::Trunc);
        $ceil = $b->since($a, roundingIncrement: 5, roundingMode: RoundingMode::Ceil);

        static::assertSame(5, $trunc->days);
        static::assertSame(10, $ceil->days);
    }

    public function testUntilForwardsSmallestUnit(): void
    {
        $a = new PlainDate(2020, 1, 1);
        $b = new PlainDate(2020, 4, 15);
        $dur = $a->until($b, largestUnit: Unit::Month, smallestUnit: Unit::Month);

        static::assertSame(3, $dur->months);
        static::assertSame(0, $dur->days);
    }

    public function testUntilForwardsRoundingMode(): void
    {
        $a = new PlainDate(2020, 1, 1);
        $b = new PlainDate(2020, 1, 8);

        $trunc = $a->until($b, roundingIncrement: 5, roundingMode: RoundingMode::Trunc);
        $ceil = $a->until($b, roundingIncrement: 5, roundingMode: RoundingMode::Ceil);

        static::assertSame(5, $trunc->days);
        static::assertSame(10, $ceil->days);
    }

    public function testUntilForwardsRoundingIncrement(): void
    {
        $a = new PlainDate(2020, 1, 1);
        $b = new PlainDate(2020, 1, 8);

        $dur = $a->until($b, roundingIncrement: 5);

        // 7 days truncated to nearest 5 = 5
        static::assertSame(5, $dur->days);
    }

    public function testSinceThrowsWhenYearsRoundingExceedsIsoRange(): void
    {
        $from = new PlainDate(1970, 1, 1);
        $to = new PlainDate(1971, 1, 1);

        $this->expectException(RangeError::class);
        $from->since(
            $to,
            largestUnit: Unit::Year,
            smallestUnit: Unit::Year,
            roundingMode: RoundingMode::HalfExpand,
            roundingIncrement: 1_000_000,
        );
    }

    public function testUntilThrowsWhenYearsRoundingExceedsIsoRange(): void
    {
        $from = new PlainDate(1970, 1, 1);
        $to = new PlainDate(1971, 1, 1);

        $this->expectException(RangeError::class);
        $from->until(
            $to,
            largestUnit: Unit::Year,
            smallestUnit: Unit::Year,
            roundingMode: RoundingMode::HalfExpand,
            roundingIncrement: 1_000_000,
        );
    }

    // -------------------------------------------------------------------------
    // Calendar::fromId
    // -------------------------------------------------------------------------

    public function testCalendarFromIdResolvesCanonical(): void
    {
        static::assertSame(Calendar::Hebrew, Calendar::fromId('hebrew'));
    }

    public function testCalendarFromIdIsCaseInsensitive(): void
    {
        static::assertSame(Calendar::Hebrew, Calendar::fromId('HEBREW'));
    }

    public function testCalendarFromIdResolvesAliases(): void
    {
        static::assertSame(Calendar::IslamicCivil, Calendar::fromId('islamicc'));
        static::assertSame(Calendar::EthiopicAmeteAlem, Calendar::fromId('ethiopic-amete-alem'));
    }

    public function testCalendarFromIdRejectsUnknown(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Calendar::fromId('bogus');
    }

    // -------------------------------------------------------------------------
    // toPlainDateTime / toZonedDateTime with PlainTime argument
    // -------------------------------------------------------------------------

    public function testToPlainDateTimeWithTime(): void
    {
        $pd = new PlainDate(2020, 6, 15);
        $time = new \Calendrics\PlainTime(13, 30, 45);
        $dt = $pd->toPlainDateTime($time);

        static::assertSame(2020, $dt->year);
        static::assertSame(6, $dt->month);
        static::assertSame(15, $dt->day);
        static::assertSame(13, $dt->hour);
        static::assertSame(30, $dt->minute);
        static::assertSame(45, $dt->second);
    }

    public function testToPlainDateTimeWithoutTimeUsesMidnight(): void
    {
        $pd = new PlainDate(2020, 6, 15);
        $dt = $pd->toPlainDateTime();

        static::assertSame(0, $dt->hour);
        static::assertSame(0, $dt->minute);
        static::assertSame(0, $dt->second);
    }

    public function testToZonedDateTimeWithTime(): void
    {
        $pd = new PlainDate(2020, 6, 15);
        $time = new \Calendrics\PlainTime(10, 30);
        $zdt = $pd->toZonedDateTime('UTC', $time);

        static::assertSame(2020, $zdt->year);
        static::assertSame(6, $zdt->month);
        static::assertSame(15, $zdt->day);
        static::assertSame(10, $zdt->hour);
        static::assertSame(30, $zdt->minute);
    }

    public function testToZonedDateTimeWithoutTimeUsesMidnight(): void
    {
        $pd = new PlainDate(2020, 6, 15);
        $zdt = $pd->toZonedDateTime('UTC');

        static::assertSame(0, $zdt->hour);
        static::assertSame(0, $zdt->minute);
    }

    // -------------------------------------------------------------------------
    // fromDateTime()
    // -------------------------------------------------------------------------

    public function testFromDateTimeReadsZoneLocalDate(): void
    {
        // 2020-06-15T22:30:00 UTC = 2020-06-16T07:30:00 Asia/Tokyo (next day in Tokyo).
        // fromDateTime must take the Tokyo-local date, not the UTC one.
        $dt = new \DateTimeImmutable('2020-06-16T07:30:00', new \DateTimeZone('Asia/Tokyo'));
        $pd = PlainDate::fromDateTime($dt);

        static::assertSame(2020, $pd->year);
        static::assertSame(6, $pd->month);
        static::assertSame(16, $pd->day); // Tokyo's date, not UTC's 15th
    }

    public function testFromDateTimeDefaultsToIsoCalendar(): void
    {
        $dt = new \DateTimeImmutable('2020-06-15T00:00:00', new \DateTimeZone('UTC'));
        $pd = PlainDate::fromDateTime($dt);

        static::assertSame(Calendar::Iso8601, $pd->calendar);
    }

    public function testFromDateTimeAcceptsExplicitCalendar(): void
    {
        $dt = new \DateTimeImmutable('2024-06-15T00:00:00', new \DateTimeZone('UTC'));
        $pd = PlainDate::fromDateTime($dt, Calendar::Hebrew);

        static::assertSame(Calendar::Hebrew, $pd->calendar);
        static::assertNotSame(2024, $pd->year);
        static::assertSame(2024, $pd->toSpec()->isoYear);
    }
}
