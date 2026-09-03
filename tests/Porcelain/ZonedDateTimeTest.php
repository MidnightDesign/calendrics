<?php

declare(strict_types=1);

namespace Calendrics\Tests\Porcelain;

use Calendrics\Calendar;
use Calendrics\CalendarDisplay;
use Calendrics\Disambiguation;
use Calendrics\Duration;
use Calendrics\Exception\RangeError;
use Calendrics\OffsetDisplay;
use Calendrics\OffsetOption;
use Calendrics\Overflow;
use Calendrics\PlainTime;
use Calendrics\RoundingMode;
use Calendrics\TimeZoneDisplay;
use Calendrics\TransitionDirection;
use Calendrics\Unit;
use Calendrics\ZonedDateTime;

final class ZonedDateTimeTest extends CalendricsTestCase
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function testConstructorUtc(): void
    {
        $zdt = new ZonedDateTime(0, 'UTC');

        static::assertSame(0, $zdt->epochNanoseconds);
        static::assertSame('UTC', $zdt->timeZoneId);
    }

    public function testConstructorWithIanaTimeZone(): void
    {
        $ns = 1_577_836_800_000_000_000; // 2020-01-01T00:00:00Z
        $zdt = new ZonedDateTime($ns, 'America/New_York');

        static::assertSame($ns, $zdt->epochNanoseconds);
        static::assertSame('America/New_York', $zdt->timeZoneId);
    }

    public function testConstructorWithFixedOffset(): void
    {
        $zdt = new ZonedDateTime(0, '+05:30');

        static::assertSame(0, $zdt->epochNanoseconds);
        static::assertSame('+05:30', $zdt->timeZoneId);
    }

    public function testConstructorInvalidTimeZoneThrows(): void
    {
        $this->expectException(RangeError::class);

        new ZonedDateTime(0, 'Invalid/Zone');
    }

    // -------------------------------------------------------------------------
    // Virtual properties - epoch
    // -------------------------------------------------------------------------

    public function testEpochNanoseconds(): void
    {
        $ns = 1_577_836_800_123_456_789;
        $zdt = new ZonedDateTime($ns, 'UTC');

        static::assertSame($ns, $zdt->epochNanoseconds);
    }

    public function testEpochMilliseconds(): void
    {
        $ns = 1_577_836_800_123_456_789;
        $zdt = new ZonedDateTime($ns, 'UTC');

        static::assertSame(1_577_836_800_123, $zdt->epochMilliseconds);
    }

    // -------------------------------------------------------------------------
    // Virtual properties - identity
    // -------------------------------------------------------------------------

    public function testTimeZoneId(): void
    {
        $zdt = new ZonedDateTime(0, 'Europe/Berlin');

        static::assertSame('Europe/Berlin', $zdt->timeZoneId);
    }

    public function testCalendarId(): void
    {
        $zdt = new ZonedDateTime(0, 'UTC');

        static::assertSame(Calendar::Iso8601, $zdt->calendar);
    }

    // -------------------------------------------------------------------------
    // Virtual properties - date/time components
    // -------------------------------------------------------------------------

    public function testDateTimeComponentsUtc(): void
    {
        // 2020-06-15T13:45:30.123456789Z
        $zdt = ZonedDateTime::parse('2020-06-15T13:45:30.123456789+00:00[UTC]');

        static::assertSame(2020, $zdt->year);
        static::assertSame(6, $zdt->month);
        static::assertSame(15, $zdt->day);
        static::assertSame(13, $zdt->hour);
        static::assertSame(45, $zdt->minute);
        static::assertSame(30, $zdt->second);
        static::assertSame(123, $zdt->millisecond);
        static::assertSame(456, $zdt->microsecond);
        static::assertSame(789, $zdt->nanosecond);
    }

    public function testDateTimeComponentsWithOffset(): void
    {
        // 2020-01-01T00:00:00Z in Asia/Kolkata is 2020-01-01T05:30:00+05:30
        $zdt = new ZonedDateTime(1_577_836_800_000_000_000, 'Asia/Kolkata');

        static::assertSame(2020, $zdt->year);
        static::assertSame(1, $zdt->month);
        static::assertSame(1, $zdt->day);
        static::assertSame(5, $zdt->hour);
        static::assertSame(30, $zdt->minute);
    }

    // -------------------------------------------------------------------------
    // Virtual properties - offset
    // -------------------------------------------------------------------------

    public function testOffsetUtc(): void
    {
        $zdt = new ZonedDateTime(0, 'UTC');

        static::assertSame('+00:00', $zdt->offset);
    }

    public function testOffsetKolkata(): void
    {
        $zdt = new ZonedDateTime(0, 'Asia/Kolkata');

        static::assertSame('+05:30', $zdt->offset);
    }

    public function testOffsetNanoseconds(): void
    {
        $zdt = new ZonedDateTime(0, 'Asia/Kolkata');

        // +05:30 = 5*3600 + 30*60 = 19800 seconds = 19800_000_000_000 ns
        static::assertSame(19800_000_000_000, $zdt->offsetNanoseconds);
    }

    // -------------------------------------------------------------------------
    // Virtual properties - calendar
    // -------------------------------------------------------------------------

    public function testMonthCode(): void
    {
        $zdt = ZonedDateTime::parse('2020-06-15T00:00:00+00:00[UTC]');

        static::assertSame('M06', $zdt->monthCode);
    }

    public function testDayOfWeek(): void
    {
        // 2020-06-15 is a Monday (1)
        $zdt = ZonedDateTime::parse('2020-06-15T00:00:00+00:00[UTC]');

        static::assertSame(1, $zdt->dayOfWeek);
    }

    public function testDayOfYear(): void
    {
        // Jan 1 is day 1
        $zdt = ZonedDateTime::parse('2020-01-01T00:00:00+00:00[UTC]');

        static::assertSame(1, $zdt->dayOfYear);
    }

    public function testWeekOfYear(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T00:00:00+00:00[UTC]');

        static::assertSame(1, $zdt->weekOfYear);
    }

    public function testYearOfWeek(): void
    {
        // 2024-12-30 (Monday) is in ISO week 1 of 2025
        $zdt = ZonedDateTime::parse('2024-12-30T00:00:00+00:00[UTC]');

        static::assertSame(2025, $zdt->yearOfWeek);
    }

    public function testDaysInMonth(): void
    {
        $zdt = ZonedDateTime::parse('2020-02-15T00:00:00+00:00[UTC]');

        static::assertSame(29, $zdt->daysInMonth); // 2020 is leap year
    }

    public function testDaysInYear(): void
    {
        $zdtLeap = ZonedDateTime::parse('2020-01-01T00:00:00+00:00[UTC]');
        $zdtNormal = ZonedDateTime::parse('2019-01-01T00:00:00+00:00[UTC]');

        static::assertSame(366, $zdtLeap->daysInYear);
        static::assertSame(365, $zdtNormal->daysInYear);
    }

    public function testDaysInWeek(): void
    {
        static::assertSame(7, ZonedDateTime::parse('2020-01-01T00:00:00+00:00[UTC]')->daysInWeek);
    }

    public function testMonthsInYear(): void
    {
        static::assertSame(12, ZonedDateTime::parse('2020-01-01T00:00:00+00:00[UTC]')->monthsInYear);
    }

    public function testInLeapYear(): void
    {
        $zdtLeap = ZonedDateTime::parse('2020-01-01T00:00:00+00:00[UTC]');
        $zdtNormal = ZonedDateTime::parse('2019-01-01T00:00:00+00:00[UTC]');

        static::assertTrue($zdtLeap->inLeapYear);
        static::assertFalse($zdtNormal->inLeapYear);
    }

    public function testHoursInDay(): void
    {
        // A normal day has 24 hours
        $zdt = ZonedDateTime::parse('2020-06-15T00:00:00+00:00[UTC]');

        static::assertSame(24, $zdt->hoursInDay);
    }

    // -------------------------------------------------------------------------
    // parse()
    // -------------------------------------------------------------------------

    public function testParseBasic(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T12:00:00+00:00[UTC]');

        static::assertSame(2020, $zdt->year);
        static::assertSame(1, $zdt->month);
        static::assertSame(1, $zdt->day);
        static::assertSame(12, $zdt->hour);
        static::assertSame('UTC', $zdt->timeZoneId);
    }

    public function testParseWithIanaTimezone(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T12:00:00+05:30[Asia/Kolkata]');

        static::assertSame('Asia/Kolkata', $zdt->timeZoneId);
        static::assertSame(12, $zdt->hour);
        static::assertSame('+05:30', $zdt->offset);
    }

    public function testParseWithFractionalSeconds(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T12:00:00.123456789+00:00[UTC]');

        static::assertSame(123, $zdt->millisecond);
        static::assertSame(456, $zdt->microsecond);
        static::assertSame(789, $zdt->nanosecond);
    }

    public function testParseInvalidStringThrows(): void
    {
        $this->expectException(RangeError::class);

        ZonedDateTime::parse('not-a-zoned-datetime');
    }

    public function testParseWithDisambiguationEarlier(): void
    {
        // During fall-back (Nov 1, 2020 in US/Eastern), 1:30 AM exists twice.
        // "earlier" should pick the EDT (summer) occurrence.
        $zdt = ZonedDateTime::parse(
            '2020-11-01T01:30:00-04:00[America/New_York]',
            disambiguation: Disambiguation::Earlier,
            offsetOption: OffsetOption::Ignore,
        );

        static::assertSame(1, $zdt->hour);
        static::assertSame(30, $zdt->minute);
        static::assertSame('-04:00', $zdt->offset);
    }

    public function testParseWithDisambiguationCompatible(): void
    {
        // "compatible" should also resolve the ambiguous time
        $zdt = ZonedDateTime::parse(
            '2020-11-01T01:30:00-04:00[America/New_York]',
            disambiguation: Disambiguation::Compatible,
            offsetOption: OffsetOption::Ignore,
        );

        static::assertSame(1, $zdt->hour);
        static::assertSame(30, $zdt->minute);
    }

    public function testParseWithOffsetOptionUse(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T12:00:00+05:30[Asia/Kolkata]', offsetOption: OffsetOption::Use);

        static::assertSame(12, $zdt->hour);
        static::assertSame('+05:30', $zdt->offset);
    }

    public function testParseWithOffsetOptionRejectValid(): void
    {
        // Offset matches the timezone -- should succeed
        $zdt = ZonedDateTime::parse('2020-01-01T12:00:00+05:30[Asia/Kolkata]', offsetOption: OffsetOption::Reject);

        static::assertSame(12, $zdt->hour);
    }

    public function testParseWithOffsetOptionRejectInvalid(): void
    {
        $this->expectException(RangeError::class);

        // Offset does not match the timezone
        ZonedDateTime::parse('2020-01-01T12:00:00+00:00[Asia/Kolkata]', offsetOption: OffsetOption::Reject);
    }

    // -------------------------------------------------------------------------
    // compare()
    // -------------------------------------------------------------------------

    public function testCompareEqual(): void
    {
        $a = new ZonedDateTime(1_000_000_000, 'UTC');
        $b = new ZonedDateTime(1_000_000_000, 'UTC');

        static::assertSame(0, ZonedDateTime::compare($a, $b));
    }

    public function testCompareLess(): void
    {
        $a = new ZonedDateTime(1_000_000_000, 'UTC');
        $b = new ZonedDateTime(2_000_000_000, 'UTC');

        static::assertSame(-1, ZonedDateTime::compare($a, $b));
    }

    public function testCompareGreater(): void
    {
        $a = new ZonedDateTime(2_000_000_000, 'UTC');
        $b = new ZonedDateTime(1_000_000_000, 'UTC');

        static::assertSame(1, ZonedDateTime::compare($a, $b));
    }

    public function testCompareDifferentTimeZonesSameInstant(): void
    {
        $ns = 1_577_836_800_000_000_000;
        $a = new ZonedDateTime($ns, 'UTC');
        $b = new ZonedDateTime($ns, 'Asia/Kolkata');

        static::assertSame(0, ZonedDateTime::compare($a, $b));
    }

    // -------------------------------------------------------------------------
    // equals()
    // -------------------------------------------------------------------------

    public function testEqualsTrue(): void
    {
        $a = new ZonedDateTime(1_577_836_800_000_000_000, 'UTC');
        $b = new ZonedDateTime(1_577_836_800_000_000_000, 'UTC');

        static::assertTrue($a->equals($b));
    }

    public function testEqualsFalseDifferentNs(): void
    {
        $a = new ZonedDateTime(1_577_836_800_000_000_000, 'UTC');
        $b = new ZonedDateTime(1_577_836_800_000_000_001, 'UTC');

        static::assertFalse($a->equals($b));
    }

    public function testEqualsFalseDifferentTimeZone(): void
    {
        $ns = 1_577_836_800_000_000_000;
        $a = new ZonedDateTime($ns, 'UTC');
        $b = new ZonedDateTime($ns, 'Asia/Kolkata');

        // equals checks instant + timezone + calendar, different timezone => false
        static::assertFalse($a->equals($b));
    }

    // -------------------------------------------------------------------------
    // with()
    // -------------------------------------------------------------------------

    public function testWithYear(): void
    {
        $zdt = ZonedDateTime::parse('2020-06-15T12:00:00+00:00[UTC]');
        $result = $zdt->with(year: 2021);

        static::assertSame(2021, $result->year);
        static::assertSame(6, $result->month);
        static::assertSame(15, $result->day);
        static::assertSame(12, $result->hour);
    }

    public function testWithMonth(): void
    {
        $zdt = ZonedDateTime::parse('2020-06-15T12:00:00+00:00[UTC]');
        $result = $zdt->with(month: 3);

        static::assertSame(2020, $result->year);
        static::assertSame(3, $result->month);
        static::assertSame(15, $result->day);
    }

    public function testWithDay(): void
    {
        $zdt = ZonedDateTime::parse('2020-06-15T12:00:00+00:00[UTC]');
        $result = $zdt->with(day: 20);

        static::assertSame(20, $result->day);
    }

    public function testWithHour(): void
    {
        $zdt = ZonedDateTime::parse('2020-06-15T12:00:00+00:00[UTC]');
        $result = $zdt->with(hour: 18);

        static::assertSame(18, $result->hour);
    }

    public function testWithMinute(): void
    {
        $zdt = ZonedDateTime::parse('2020-06-15T12:00:00+00:00[UTC]');
        $result = $zdt->with(minute: 45);

        static::assertSame(45, $result->minute);
    }

    public function testWithConstrainsDay(): void
    {
        // Jan 31 -> month 2 with constrain should give Feb 29 (2020 is leap)
        $zdt = ZonedDateTime::parse('2020-01-31T12:00:00+00:00[UTC]');
        $result = $zdt->with(month: 2, overflow: Overflow::Constrain);

        static::assertSame(29, $result->day);
    }

    public function testWithRejectOverflow(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-31T12:00:00+00:00[UTC]');

        $this->expectException(RangeError::class);

        $zdt->with(month: 2, overflow: Overflow::Reject);
    }

    public function testWithDoesNotMutateOriginal(): void
    {
        $zdt = ZonedDateTime::parse('2020-06-15T12:00:00+00:00[UTC]');
        $zdt->with(year: 2021);

        static::assertSame(2020, $zdt->year);
    }

    // -------------------------------------------------------------------------
    // add() / subtract()
    // -------------------------------------------------------------------------

    public function testAddHours(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T00:00:00+00:00[UTC]');
        $result = $zdt->add(new Duration(hours: 2));

        static::assertSame(2, $result->hour);
    }

    public function testAddDays(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T12:00:00+00:00[UTC]');
        $result = $zdt->add(new Duration(days: 10));

        static::assertSame(11, $result->day);
        static::assertSame(12, $result->hour);
    }

    public function testAddMonths(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-31T12:00:00+00:00[UTC]');
        $result = $zdt->add(new Duration(months: 1));

        // Feb 2020 has 29 days, so Jan 31 + 1 month constrains to Feb 29
        static::assertSame(2, $result->month);
        static::assertSame(29, $result->day);
    }

    public function testAddYears(): void
    {
        $zdt = ZonedDateTime::parse('2020-02-29T12:00:00+00:00[UTC]');
        $result = $zdt->add(new Duration(years: 1));

        // 2021 is not a leap year, so Feb 29 constrains to Feb 28
        static::assertSame(2021, $result->year);
        static::assertSame(2, $result->month);
        static::assertSame(28, $result->day);
    }

    public function testAddDoesNotMutateOriginal(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T00:00:00+00:00[UTC]');
        $zdt->add(new Duration(hours: 1));

        static::assertSame(0, $zdt->hour);
    }

    public function testSubtractHours(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T12:00:00+00:00[UTC]');
        $result = $zdt->subtract(new Duration(hours: 3));

        static::assertSame(9, $result->hour);
    }

    public function testSubtractDays(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-11T12:00:00+00:00[UTC]');
        $result = $zdt->subtract(new Duration(days: 10));

        static::assertSame(1, $result->day);
    }

    public function testSubtractAcrossMonthBoundary(): void
    {
        $zdt = ZonedDateTime::parse('2020-03-01T12:00:00+00:00[UTC]');
        $result = $zdt->subtract(new Duration(days: 1));

        static::assertSame(2, $result->month);
        static::assertSame(29, $result->day); // 2020 is leap year
    }

    // -------------------------------------------------------------------------
    // round()
    // -------------------------------------------------------------------------

    public function testRoundToHour(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T13:45:30+00:00[UTC]');
        $result = $zdt->round(Unit::Hour);

        static::assertSame(14, $result->hour);
        static::assertSame(0, $result->minute);
        static::assertSame(0, $result->second);
    }

    public function testRoundToMinute(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T13:45:30+00:00[UTC]');
        $result = $zdt->round(Unit::Minute);

        static::assertSame(46, $result->minute);
        static::assertSame(0, $result->second);
    }

    public function testRoundToSecondTrunc(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T13:45:30.999+00:00[UTC]');
        $result = $zdt->round(Unit::Second, RoundingMode::Trunc);

        static::assertSame(30, $result->second);
        static::assertSame(0, $result->millisecond);
    }

    public function testRoundWithIncrement(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T13:47:00+00:00[UTC]');
        $result = $zdt->round(Unit::Minute, RoundingMode::HalfExpand, 15);

        static::assertSame(45, $result->minute);
    }

    // -------------------------------------------------------------------------
    // since() / until()
    // -------------------------------------------------------------------------

    public function testSinceBasic(): void
    {
        $a = ZonedDateTime::parse('2020-01-01T00:00:00+00:00[UTC]');
        $b = ZonedDateTime::parse('2020-01-01T02:30:00+00:00[UTC]');
        $d = $b->since($a);

        static::assertSame(2, $d->hours);
        static::assertSame(30, $d->minutes);
    }

    public function testSinceNegative(): void
    {
        $a = ZonedDateTime::parse('2020-01-01T02:30:00+00:00[UTC]');
        $b = ZonedDateTime::parse('2020-01-01T00:00:00+00:00[UTC]');
        $d = $b->since($a);

        static::assertSame(-2, $d->hours);
        static::assertSame(-30, $d->minutes);
    }

    public function testSinceWithLargestUnit(): void
    {
        $a = ZonedDateTime::parse('2020-01-01T00:00:00+00:00[UTC]');
        $b = ZonedDateTime::parse('2020-04-01T00:00:00+00:00[UTC]');
        $d = $b->since($a, largestUnit: Unit::Month);

        static::assertSame(3, $d->months);
    }

    public function testSinceWithSmallestUnit(): void
    {
        $a = ZonedDateTime::parse('2020-01-01T00:00:00+00:00[UTC]');
        $b = ZonedDateTime::parse('2020-01-01T01:30:45+00:00[UTC]');
        $d = $b->since($a, smallestUnit: Unit::Minute);

        static::assertSame(1, $d->hours);
        static::assertSame(30, $d->minutes);
        static::assertSame(0, $d->seconds);
    }

    public function testUntilBasic(): void
    {
        $a = ZonedDateTime::parse('2020-01-01T00:00:00+00:00[UTC]');
        $b = ZonedDateTime::parse('2020-01-01T03:00:00+00:00[UTC]');
        $d = $a->until($b);

        static::assertSame(3, $d->hours);
    }

    public function testUntilNegative(): void
    {
        $a = ZonedDateTime::parse('2020-01-01T03:00:00+00:00[UTC]');
        $b = ZonedDateTime::parse('2020-01-01T00:00:00+00:00[UTC]');
        $d = $a->until($b);

        static::assertSame(-3, $d->hours);
    }

    public function testUntilWithLargestUnit(): void
    {
        $a = ZonedDateTime::parse('2020-01-01T00:00:00+00:00[UTC]');
        $b = ZonedDateTime::parse('2021-06-15T00:00:00+00:00[UTC]');
        $d = $a->until($b, largestUnit: Unit::Year);

        static::assertSame(1, $d->years);
        static::assertSame(5, $d->months);
        static::assertSame(14, $d->days);
    }

    // -------------------------------------------------------------------------
    // Conversion methods
    // -------------------------------------------------------------------------

    public function testToInstant(): void
    {
        $ns = 1_577_836_800_000_000_000;
        $zdt = new ZonedDateTime($ns, 'America/New_York');
        $instant = $zdt->toInstant();

        static::assertSame($ns, $instant->epochNanoseconds);
    }

    public function testToPlainDate(): void
    {
        // 2020-01-01T00:00:00Z in Kolkata is 2020-01-01T05:30:00+05:30
        $zdt = new ZonedDateTime(1_577_836_800_000_000_000, 'Asia/Kolkata');
        $pd = $zdt->toPlainDate();

        static::assertSame(2020, $pd->year);
        static::assertSame(1, $pd->month);
        static::assertSame(1, $pd->day);
    }

    public function testToPlainTime(): void
    {
        $zdt = ZonedDateTime::parse('2020-06-15T13:45:30.123456789+00:00[UTC]');
        $pt = $zdt->toPlainTime();

        static::assertPlainTimeIs(13, 45, 30, 123, 456, 789, $pt);
    }

    public function testToPlainDateTime(): void
    {
        $zdt = ZonedDateTime::parse('2020-06-15T13:45:30+00:00[UTC]');
        $pdt = $zdt->toPlainDateTime();

        static::assertSame(2020, $pdt->year);
        static::assertSame(6, $pdt->month);
        static::assertSame(15, $pdt->day);
        static::assertSame(13, $pdt->hour);
        static::assertSame(45, $pdt->minute);
        static::assertSame(30, $pdt->second);
    }

    // -------------------------------------------------------------------------
    // withTimeZone()
    // -------------------------------------------------------------------------

    public function testWithTimeZone(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T00:00:00+00:00[UTC]');
        $result = $zdt->withTimeZone('America/New_York');

        // Same instant, different time zone display
        static::assertSame($zdt->epochNanoseconds, $result->epochNanoseconds);
        static::assertSame('America/New_York', $result->timeZoneId);
        // In winter, EST is UTC-5
        static::assertSame(19, $result->hour);
        static::assertSame(12, $result->month); // Dec 31 in NY
    }

    public function testWithTimeZonePreservesEpochNanoseconds(): void
    {
        $ns = 1_577_836_800_123_456_789;
        $zdt = new ZonedDateTime($ns, 'UTC');
        $result = $zdt->withTimeZone('Europe/Berlin');

        static::assertSame($ns, $result->epochNanoseconds);
    }

    // -------------------------------------------------------------------------
    // withPlainTime()
    // -------------------------------------------------------------------------

    public function testWithPlainTimeReplacesTime(): void
    {
        $zdt = ZonedDateTime::parse('2020-06-15T12:00:00+00:00[UTC]');
        $result = $zdt->withPlainTime(new PlainTime(18, 30, 45));

        static::assertSame(2020, $result->year);
        static::assertSame(6, $result->month);
        static::assertSame(15, $result->day);
        static::assertSame(18, $result->hour);
        static::assertSame(30, $result->minute);
        static::assertSame(45, $result->second);
    }

    public function testWithPlainTimeNullSetsMidnight(): void
    {
        $zdt = ZonedDateTime::parse('2020-06-15T12:00:00+00:00[UTC]');
        $result = $zdt->withPlainTime();

        static::assertSame(0, $result->hour);
        static::assertSame(0, $result->minute);
        static::assertSame(0, $result->second);
        static::assertSame(15, $result->day);
    }

    // -------------------------------------------------------------------------
    // startOfDay()
    // -------------------------------------------------------------------------

    public function testStartOfDay(): void
    {
        $zdt = ZonedDateTime::parse('2020-06-15T13:45:30+00:00[UTC]');
        $sod = $zdt->startOfDay();

        static::assertSame(2020, $sod->year);
        static::assertSame(6, $sod->month);
        static::assertSame(15, $sod->day);
        static::assertSame(0, $sod->hour);
        static::assertSame(0, $sod->minute);
        static::assertSame(0, $sod->second);
        static::assertSame('UTC', $sod->timeZoneId);
    }

    public function testStartOfDayPreservesDate(): void
    {
        $zdt = ZonedDateTime::parse('2020-12-31T23:59:59+00:00[UTC]');
        $sod = $zdt->startOfDay();

        static::assertSame(2020, $sod->year);
        static::assertSame(12, $sod->month);
        static::assertSame(31, $sod->day);
    }

    // -------------------------------------------------------------------------
    // getTimeZoneTransition()
    // -------------------------------------------------------------------------

    public function testGetTimeZoneTransitionNext(): void
    {
        // US Eastern has DST transitions. The method returns the next transition
        // point after the current instant.
        $zdt = ZonedDateTime::parse('2020-01-01T00:00:00-05:00[America/New_York]');
        $next = $zdt->getTimeZoneTransition(TransitionDirection::Next);

        static::assertNotNull($next);
        static::assertSame('America/New_York', $next->timeZoneId);
    }

    public function testGetTimeZoneTransitionPrevious(): void
    {
        $zdt = ZonedDateTime::parse('2020-06-01T00:00:00-04:00[America/New_York]');
        $prev = $zdt->getTimeZoneTransition(TransitionDirection::Previous);

        static::assertNotNull($prev);
        // Previous transition from June 2020 should be March 2020 (spring forward)
        static::assertSame(2020, $prev->year);
        static::assertSame(3, $prev->month);
    }

    public function testGetTimeZoneTransitionFixedOffsetReturnsNull(): void
    {
        $zdt = new ZonedDateTime(0, '+05:30');

        static::assertNull($zdt->getTimeZoneTransition(TransitionDirection::Next));
    }

    public function testGetTimeZoneTransitionUtcReturnsNull(): void
    {
        $zdt = new ZonedDateTime(0, 'UTC');

        static::assertNull($zdt->getTimeZoneTransition(TransitionDirection::Next));
    }

    /**
     * A tzdb rule change is only a transition if the UTC offset moves. Yukon left
     * DST in November 2020 by keeping PDT's -07:00 year-round under the name MST,
     * so from June 2020 the zone's remaining history is one entry that renames the
     * offset and never changes it — which is no next transition at all.
     */
    public function testGetTimeZoneTransitionNextIsNullWhenLaterEntriesKeepTheOffset(): void
    {
        $zdt = ZonedDateTime::parse('2020-06-01T00:00:00-07:00[America/Whitehorse]');

        static::assertNotNull(
            $zdt->getTimeZoneTransition(TransitionDirection::Previous),
            'Whitehorse must still have offset transitions in its past, or a null "next" proves nothing',
        );
        static::assertNull($zdt->getTimeZoneTransition(TransitionDirection::Next));
    }

    /**
     * Africa/Abidjan's only offset transition is its 1912 move off local mean time.
     * Anchored on that very instant there is nothing earlier to find: "previous"
     * means strictly before, so the transition the anchor sits on does not count.
     */
    public function testGetTimeZoneTransitionPreviousIsNullOnTheZonesFirstTransition(): void
    {
        $firstTransition = -1_830_383_032_000_000_000;
        $zdt = new ZonedDateTime($firstTransition, 'Africa/Abidjan');

        static::assertSame(
            $firstTransition,
            new ZonedDateTime(
                $firstTransition - 1,
                'Africa/Abidjan',
            )->getTimeZoneTransition(TransitionDirection::Next)?->epochNanoseconds,
            'the anchor must sit exactly on the transition, or a null "previous" proves nothing',
        );
        static::assertNull($zdt->getTimeZoneTransition(TransitionDirection::Previous));
    }

    // -------------------------------------------------------------------------
    // toString() with enum params
    // -------------------------------------------------------------------------

    public function testToStringDefault(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T12:00:00+00:00[UTC]');

        static::assertSame('2020-01-01T12:00:00+00:00[UTC]', $zdt->toString());
    }

    public function testToStringWithFractionalSecondDigits(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T12:00:00.100+00:00[UTC]');

        static::assertSame('2020-01-01T12:00:00.100+00:00[UTC]', $zdt->toString(fractionalSecondDigits: 3));
    }

    public function testToStringWithFractionalSecondDigitsZero(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T12:00:00.500+00:00[UTC]');

        static::assertSame('2020-01-01T12:00:00+00:00[UTC]', $zdt->toString(fractionalSecondDigits: 0));
    }

    public function testToStringWithSmallestUnit(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T12:30:45+00:00[UTC]');

        // smallestUnit: minute produces HH:MM format (no seconds)
        static::assertSame('2020-01-01T12:30+00:00[UTC]', $zdt->toString(smallestUnit: Unit::Minute));
    }

    public function testToStringWithRoundingMode(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T12:00:00.600+00:00[UTC]');

        static::assertSame('2020-01-01T12:00:01+00:00[UTC]', $zdt->toString(
            fractionalSecondDigits: 0,
            roundingMode: RoundingMode::Ceil,
        ));
    }

    public function testToStringOffsetDisplayNever(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T12:00:00+00:00[UTC]');

        $str = $zdt->toString(offset: OffsetDisplay::Never);

        static::assertStringNotContainsString('+00:00', $str);
        static::assertStringContainsString('[UTC]', $str);
    }

    public function testToStringTimeZoneDisplayNever(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T12:00:00+00:00[UTC]');

        $str = $zdt->toString(timeZoneName: TimeZoneDisplay::Never);

        static::assertStringNotContainsString('[UTC]', $str);
        static::assertStringContainsString('+00:00', $str);
    }

    public function testToStringTimeZoneDisplayCritical(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T12:00:00+00:00[UTC]');

        $str = $zdt->toString(timeZoneName: TimeZoneDisplay::Critical);

        static::assertStringContainsString('[!UTC]', $str);
    }

    public function testToStringCalendarDisplayAlways(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T12:00:00+00:00[UTC]');

        $str = $zdt->toString(calendarName: CalendarDisplay::Always);

        static::assertStringContainsString('[u-ca=iso8601]', $str);
    }

    public function testToStringCalendarDisplayNever(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T12:00:00+00:00[UTC]');

        $str = $zdt->toString(calendarName: CalendarDisplay::Never);

        static::assertStringNotContainsString('u-ca=', $str);
    }

    public function testToStringCalendarDisplayCritical(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T12:00:00+00:00[UTC]');

        $str = $zdt->toString(calendarName: CalendarDisplay::Critical);

        static::assertStringContainsString('[!u-ca=iso8601]', $str);
    }

    // -------------------------------------------------------------------------
    // __toString() / jsonSerialize()
    // -------------------------------------------------------------------------

    public function testMagicToString(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T12:00:00+00:00[UTC]');

        static::assertSame('2020-01-01T12:00:00+00:00[UTC]', (string) $zdt);
    }

    public function testJsonSerialize(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T12:00:00+00:00[UTC]');

        static::assertSame('"2020-01-01T12:00:00+00:00[UTC]"', json_encode($zdt));
    }

    // -------------------------------------------------------------------------
    // toSpec() / fromSpec()
    // -------------------------------------------------------------------------

    public function testToSpecReturnsSpecZonedDateTime(): void
    {
        $ns = 1_577_836_800_000_000_000;
        $zdt = new ZonedDateTime($ns, 'UTC');
        $spec = $zdt->toSpec();

        static::assertSame($ns, $spec->epochNanoseconds);
        static::assertSame('UTC', $spec->timeZoneId);
    }

    public function testFromSpecRoundTrip(): void
    {
        $ns = 1_577_836_800_123_456_789;
        $zdt = new ZonedDateTime($ns, 'America/New_York');
        $restored = ZonedDateTime::fromSpec($zdt->toSpec());

        static::assertTrue($zdt->equals($restored));
    }

    public function testFromSpecCreatesCorrectInstance(): void
    {
        $spec = new \Calendrics\Spec\ZonedDateTime(0, 'Europe/Berlin');
        $zdt = ZonedDateTime::fromSpec($spec);

        static::assertSame(0, $zdt->epochNanoseconds);
        static::assertSame('Europe/Berlin', $zdt->timeZoneId);
    }

    // -------------------------------------------------------------------------
    // __debugInfo()
    // -------------------------------------------------------------------------

    public function testDebugInfoContainsExpectedKeys(): void
    {
        $zdt = ZonedDateTime::parse('2020-06-15T13:45:30.123456789+00:00[UTC]');
        $info = $zdt->__debugInfo();

        static::assertArrayHasKey('epochNanoseconds', $info);
        static::assertArrayHasKey('timeZoneId', $info);
        static::assertArrayHasKey('calendar', $info);
        static::assertArrayHasKey('string', $info);
        static::assertArrayHasKey('year', $info);
        static::assertArrayHasKey('month', $info);
        static::assertArrayHasKey('day', $info);
        static::assertArrayHasKey('hour', $info);
        static::assertArrayHasKey('minute', $info);
        static::assertArrayHasKey('second', $info);
        static::assertArrayHasKey('millisecond', $info);
        static::assertArrayHasKey('microsecond', $info);
        static::assertArrayHasKey('nanosecond', $info);
        static::assertArrayHasKey('offset', $info);

        static::assertSame(2020, $info['year']);
        static::assertSame(6, $info['month']);
        static::assertSame(15, $info['day']);
        static::assertSame(13, $info['hour']);
        static::assertSame('UTC', $info['timeZoneId']);
        static::assertSame('iso8601', $info['calendar']);
    }

    // -------------------------------------------------------------------------
    // Mutation coverage: parse() forwards disambiguation option
    // -------------------------------------------------------------------------

    public function testParseForwardsDisambiguation(): void
    {
        // 2024-11-03T01:30 is ambiguous in America/New_York (fall-back DST)
        $earlier = ZonedDateTime::parse(
            '2024-11-03T01:30:00-04:00[America/New_York]',
            disambiguation: Disambiguation::Earlier,
            offsetOption: OffsetOption::Ignore,
        );
        $later = ZonedDateTime::parse(
            '2024-11-03T01:30:00-04:00[America/New_York]',
            disambiguation: Disambiguation::Later,
            offsetOption: OffsetOption::Ignore,
        );

        // Earlier gets EDT (-04:00), Later gets EST (-05:00)
        static::assertSame('-04:00', $earlier->offset);
        static::assertSame('-05:00', $later->offset);
    }

    // -------------------------------------------------------------------------
    // Mutation coverage: parse() forwards offset option
    // -------------------------------------------------------------------------

    public function testParseForwardsOffsetOptionIgnore(): void
    {
        // The offset +01:00 doesn't match UTC, but "ignore" ignores offset
        $zdt = ZonedDateTime::parse('2020-01-01T12:00:00+01:00[UTC]', offsetOption: OffsetOption::Ignore);

        static::assertSame(12, $zdt->hour);
        static::assertSame('+00:00', $zdt->offset);
    }

    // -------------------------------------------------------------------------
    // Mutation coverage: with() forwards disambiguation option
    // -------------------------------------------------------------------------

    public function testWithForwardsDisambiguationLater(): void
    {
        // Fall-back: 1:30 AM is ambiguous in New York on Nov 1 2020.
        // With offset=ignore, disambiguation decides. Default (compatible)
        // picks -04:00 (first), but Later picks -05:00 (second).
        $zdt = ZonedDateTime::parse('2020-11-01T00:00:00-04:00[America/New_York]');
        $result = $zdt->with(
            hour: 1,
            minute: 30,
            disambiguation: Disambiguation::Later,
            offsetOption: OffsetOption::Ignore,
        );

        static::assertSame(1, $result->hour);
        static::assertSame(30, $result->minute);
        static::assertSame('-05:00', $result->offset);
    }

    // -------------------------------------------------------------------------
    // Mutation coverage: with() forwards offset option
    // -------------------------------------------------------------------------

    public function testWithForwardsOffsetOptionIgnore(): void
    {
        // During DST overlap, stored offset is -05:00 (second occurrence).
        // Default (prefer) keeps -05:00; Ignore discards it and picks
        // -04:00 (first occurrence via default compatible disambiguation).
        $zdt = ZonedDateTime::parse('2020-11-01T01:30:00-05:00[America/New_York]');
        $result = $zdt->with(minute: 0, offsetOption: OffsetOption::Ignore);

        static::assertSame('-04:00', $result->offset);
    }

    // -------------------------------------------------------------------------
    // Mutation coverage: add/subtract forward overflow option
    // -------------------------------------------------------------------------

    public function testAddForwardsOverflowReject(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-31T12:00:00+00:00[UTC]');
        $this->expectException(RangeError::class);
        $zdt->add(new Duration(months: 1), Overflow::Reject);
    }

    public function testSubtractForwardsOverflowReject(): void
    {
        $zdt = ZonedDateTime::parse('2020-03-31T12:00:00+00:00[UTC]');
        $this->expectException(RangeError::class);
        $zdt->subtract(new Duration(months: 1), Overflow::Reject);
    }

    // -------------------------------------------------------------------------
    // Mutation coverage: since() forwards all options
    // -------------------------------------------------------------------------

    public function testSinceForwardsSmallestUnit(): void
    {
        $a = ZonedDateTime::parse('2020-01-01T00:00:00+00:00[UTC]');
        $b = ZonedDateTime::parse('2020-01-01T01:30:45+00:00[UTC]');
        $d = $b->since($a, smallestUnit: Unit::Minute);

        static::assertSame(1, $d->hours);
        static::assertSame(30, $d->minutes);
        static::assertSame(0, $d->seconds);
    }

    public function testSinceForwardsRoundingMode(): void
    {
        $a = ZonedDateTime::parse('2020-01-01T00:00:00+00:00[UTC]');
        $b = ZonedDateTime::parse('2020-01-01T00:00:29+00:00[UTC]');

        $trunc = $b->since($a, smallestUnit: Unit::Minute, roundingMode: RoundingMode::Trunc);
        $ceil = $b->since($a, smallestUnit: Unit::Minute, roundingMode: RoundingMode::Ceil);

        static::assertSame(0, $trunc->minutes);
        static::assertSame(1, $ceil->minutes);
    }

    public function testSinceForwardsRoundingIncrement(): void
    {
        $a = ZonedDateTime::parse('2020-01-01T00:00:00+00:00[UTC]');
        $b = ZonedDateTime::parse('2020-01-01T00:07:00+00:00[UTC]');
        $d = $b->since($a, smallestUnit: Unit::Minute, roundingIncrement: 5);

        // 7 minutes truncated to nearest 5 = 5
        static::assertSame(5, $d->minutes);
    }

    // -------------------------------------------------------------------------
    // Mutation coverage: until() forwards all options
    // -------------------------------------------------------------------------

    public function testUntilForwardsSmallestUnit(): void
    {
        $a = ZonedDateTime::parse('2020-01-01T00:00:00+00:00[UTC]');
        $b = ZonedDateTime::parse('2020-01-01T01:30:45+00:00[UTC]');
        $d = $a->until($b, smallestUnit: Unit::Minute);

        static::assertSame(1, $d->hours);
        static::assertSame(30, $d->minutes);
        static::assertSame(0, $d->seconds);
    }

    public function testUntilForwardsRoundingMode(): void
    {
        $a = ZonedDateTime::parse('2020-01-01T00:00:00+00:00[UTC]');
        $b = ZonedDateTime::parse('2020-01-01T00:00:29+00:00[UTC]');

        $trunc = $a->until($b, smallestUnit: Unit::Minute, roundingMode: RoundingMode::Trunc);
        $ceil = $a->until($b, smallestUnit: Unit::Minute, roundingMode: RoundingMode::Ceil);

        static::assertSame(0, $trunc->minutes);
        static::assertSame(1, $ceil->minutes);
    }

    public function testUntilForwardsRoundingIncrement(): void
    {
        $a = ZonedDateTime::parse('2020-01-01T00:00:00+00:00[UTC]');
        $b = ZonedDateTime::parse('2020-01-01T00:07:00+00:00[UTC]');
        $d = $a->until($b, smallestUnit: Unit::Minute, roundingIncrement: 5);

        // 7 minutes truncated to nearest 5 = 5
        static::assertSame(5, $d->minutes);
    }

    // -------------------------------------------------------------------------
    // Mutation coverage: round() forwards roundingIncrement
    // -------------------------------------------------------------------------

    public function testRoundForwardsRoundingIncrement(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T13:07:00+00:00[UTC]');
        $result = $zdt->round(Unit::Minute, RoundingMode::Trunc, 5);

        static::assertSame(5, $result->minute);
    }

    public function testRoundDefaultRoundingIncrementIsOne(): void
    {
        // With default roundingIncrement=1 and Trunc, 13:07:29 rounds to 13:07:00
        // With roundingIncrement=2, it would round to 13:06:00
        $zdt = ZonedDateTime::parse('2020-01-01T13:07:29+00:00[UTC]');
        $result = $zdt->round(Unit::Minute, RoundingMode::Trunc);

        static::assertSame(7, $result->minute);
    }

    // -------------------------------------------------------------------------
    // Calendar enum property
    // -------------------------------------------------------------------------

    public function testCalendarPropertyReturnsEnum(): void
    {
        $zdt = new ZonedDateTime(0, 'UTC');

        static::assertSame(Calendar::Iso8601, $zdt->calendar);
    }

    public function testCalendarPropertyWithNonIsoCalendar(): void
    {
        $zdt = new ZonedDateTime(0, 'UTC', Calendar::Hebrew);

        static::assertSame(Calendar::Hebrew, $zdt->calendar);
    }

    public function testConstructorAcceptsCalendarEnum(): void
    {
        $zdt = new ZonedDateTime(0, 'UTC', Calendar::Japanese);

        static::assertSame('japanese', $zdt->calendar->value);
    }

    // -------------------------------------------------------------------------
    // era / eraYear properties
    // -------------------------------------------------------------------------

    public function testEraAndEraYearNullForIsoCalendar(): void
    {
        $zdt = ZonedDateTime::parse('2020-06-15T00:00:00+00:00[UTC]');

        static::assertNull($zdt->era);
        static::assertNull($zdt->eraYear);
    }

    public function testEraAndEraYearForGregoryCalendar(): void
    {
        $zdt = new ZonedDateTime(0, 'UTC', Calendar::Gregory);

        static::assertSame('ce', $zdt->era);
        static::assertNotNull($zdt->eraYear);
    }

    // -------------------------------------------------------------------------
    // weekOfYear / yearOfWeek nullable
    // -------------------------------------------------------------------------

    public function testWeekOfYearNonNullForIso(): void
    {
        $zdt = ZonedDateTime::parse('2020-01-01T00:00:00+00:00[UTC]');

        static::assertNotNull($zdt->weekOfYear);
        static::assertNotNull($zdt->yearOfWeek);
    }

    // -------------------------------------------------------------------------
    // fromFields()
    // -------------------------------------------------------------------------

    public function testFromFields(): void
    {
        $zdt = ZonedDateTime::fromFields(timeZone: 'UTC', year: 2020, month: 6, day: 15, hour: 12, minute: 30);

        static::assertSame(2020, $zdt->year);
        static::assertSame(6, $zdt->month);
        static::assertSame(15, $zdt->day);
        static::assertSame(12, $zdt->hour);
        static::assertSame(30, $zdt->minute);
        static::assertSame('UTC', $zdt->timeZoneId);
    }

    public function testFromFieldsForwardsCalendar(): void
    {
        $zdt = ZonedDateTime::fromFields(timeZone: 'UTC', year: 2024, month: 1, day: 15, calendar: Calendar::Gregory);

        static::assertSame(Calendar::Gregory, $zdt->calendar);
    }

    public function testFromFieldsForwardsOverflowReject(): void
    {
        $this->expectException(RangeError::class);

        ZonedDateTime::fromFields(timeZone: 'UTC', year: 2020, month: 2, day: 30, overflow: Overflow::Reject);
    }

    public function testFromFieldsForwardsAllTimeFields(): void
    {
        $zdt = ZonedDateTime::fromFields(
            timeZone: 'UTC',
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

        static::assertSame(13, $zdt->hour);
        static::assertSame(45, $zdt->minute);
        static::assertSame(30, $zdt->second);
        static::assertSame(123, $zdt->millisecond);
        static::assertSame(456, $zdt->microsecond);
        static::assertSame(789, $zdt->nanosecond);
    }

    public function testFromFieldsForwardsDisambiguationReject(): void
    {
        // 2024-10-27 02:30 in Europe/Berlin is ambiguous (fall-back DST).
        $this->expectException(RangeError::class);

        ZonedDateTime::fromFields(
            timeZone: 'Europe/Berlin',
            year: 2024,
            month: 10,
            day: 27,
            hour: 2,
            minute: 30,
            disambiguation: Disambiguation::Reject,
        );
    }

    public function testFromFieldsForwardsOffsetOptionIgnore(): void
    {
        // Offset '+05:30' does not match UTC. The spec's default offset option is
        // 'reject', which would throw; `OffsetOption::Ignore` must be forwarded
        // for this call to succeed.
        $zdt = ZonedDateTime::fromFields(
            timeZone: 'UTC',
            year: 2020,
            month: 1,
            day: 1,
            offset: '+05:30',
            offsetOption: OffsetOption::Ignore,
        );

        static::assertSame('+00:00', $zdt->offset);
    }

    // -------------------------------------------------------------------------
    // withCalendar()
    // -------------------------------------------------------------------------

    public function testWithCalendarChangesCalendar(): void
    {
        $zdt = new ZonedDateTime(0, 'UTC');
        $hebrew = $zdt->withCalendar(Calendar::Hebrew);

        static::assertSame('hebrew', $hebrew->calendar->value);
        // Same instant
        static::assertSame($zdt->epochNanoseconds, $hebrew->epochNanoseconds);
        static::assertSame('UTC', $hebrew->timeZoneId);
    }

    public function testWithCalendarPreservesEpochAndTimeZone(): void
    {
        $ns = 1_577_836_800_000_000_000;
        $zdt = new ZonedDateTime($ns, 'America/New_York');
        $result = $zdt->withCalendar(Calendar::Japanese);

        static::assertSame($ns, $result->epochNanoseconds);
        static::assertSame('America/New_York', $result->timeZoneId);
        static::assertSame(Calendar::Japanese, $result->calendar);
    }

    // -------------------------------------------------------------------------
    // with() calendar-specific fields
    // -------------------------------------------------------------------------

    public function testWithMonthCode(): void
    {
        $zdt = ZonedDateTime::parse('2020-06-15T12:00:00+00:00[UTC]');
        $result = $zdt->with(monthCode: 'M03', day: 1);

        static::assertSame(3, $result->month);
        static::assertSame(1, $result->day);
    }

    // -------------------------------------------------------------------------
    // fromSpec() uses Calendar enum
    // -------------------------------------------------------------------------

    public function testFromSpecWithNonIsoCalendar(): void
    {
        $spec = new \Calendrics\Spec\ZonedDateTime(0, 'UTC', 'hebrew');
        $zdt = ZonedDateTime::fromSpec($spec);

        static::assertSame('hebrew', $zdt->calendar->value);
    }

    // -------------------------------------------------------------------------
    // Mutation coverage: with() forwards era/eraYear
    // -------------------------------------------------------------------------

    public function testWithForwardsEraAndEraYear(): void
    {
        $zdt = ZonedDateTime::parse('2024-06-15T12:00:00+00:00[UTC][u-ca=gregory]');
        $zdt2 = $zdt->with(era: 'ce', eraYear: 2020);

        static::assertSame(2020, $zdt2->year);
    }

    public function testFromFieldsForwardsEraAndEraYear(): void
    {
        $zdt = ZonedDateTime::fromFields(
            timeZone: 'UTC',
            year: 2020,
            month: 6,
            day: 15,
            hour: 12,
            calendar: Calendar::Gregory,
            era: 'ce',
            eraYear: 2020,
        );

        static::assertSame(2020, $zdt->year);
        static::assertSame('ce', $zdt->era);
        static::assertSame(2020, $zdt->eraYear);
    }

    // -------------------------------------------------------------------------
    // fromDateTime() / toDateTime()
    // -------------------------------------------------------------------------

    public function testFromDateTimeIanaZoneRoundTrip(): void
    {
        $dt = new \DateTimeImmutable('2020-06-15T14:30:45.123456', new \DateTimeZone('Europe/Vienna'));
        $zdt = ZonedDateTime::fromDateTime($dt);

        static::assertSame('Europe/Vienna', $zdt->timeZoneId);
        static::assertSame(2020, $zdt->year);
        static::assertSame(6, $zdt->month);
        static::assertSame(15, $zdt->day);
        static::assertSame(14, $zdt->hour);
        static::assertSame(30, $zdt->minute);
        static::assertSame(45, $zdt->second);
        static::assertSame(123, $zdt->millisecond);
        static::assertSame(456, $zdt->microsecond);
        static::assertSame(0, $zdt->nanosecond);

        $back = $zdt->toDateTime();
        static::assertSame('Europe/Vienna', $back->format('e'));
        static::assertSame('2020-06-15T14:30:45.123456+02:00', $back->format('Y-m-d\TH:i:s.uP'));
    }

    public function testFromDateTimeFixedOffsetZonePreservesOffsetString(): void
    {
        $dt = new \DateTimeImmutable('2020-06-15T18:00:45.000123', new \DateTimeZone('+05:30'));
        $zdt = ZonedDateTime::fromDateTime($dt);

        static::assertSame('+05:30', $zdt->timeZoneId);
        static::assertSame(18, $zdt->hour);

        $back = $zdt->toDateTime();
        static::assertSame('+05:30', $back->format('e'));
        static::assertSame('2020-06-15T18:00:45.000123+05:30', $back->format('Y-m-d\TH:i:s.uP'));
    }

    public function testFromDateTimeUtc(): void
    {
        $dt = new \DateTimeImmutable('2020-06-15T12:30:00', new \DateTimeZone('UTC'));
        $zdt = ZonedDateTime::fromDateTime($dt);

        static::assertSame('UTC', $zdt->timeZoneId);
        static::assertSame(1_592_224_200_000_000_000, $zdt->epochNanoseconds);

        $back = $zdt->toDateTime();
        static::assertSame('UTC', $back->format('e'));
    }

    public function testFromDateTimeNegativeEpochRoundTrips(): void
    {
        // 1.5 ms before epoch in UTC.
        $dt = new \DateTimeImmutable('1969-12-31T23:59:59.998500', new \DateTimeZone('UTC'));
        $zdt = ZonedDateTime::fromDateTime($dt);

        static::assertSame(-1_500_000, $zdt->epochNanoseconds);

        $back = $zdt->toDateTime();
        static::assertSame('1969-12-31T23:59:59.998500+00:00', $back->format('Y-m-d\TH:i:s.uP'));
    }

    public function testFromDateTimeDropsSubMicrosecondBits(): void
    {
        $zdt = new ZonedDateTime(1_592_224_245_123_456_789, 'UTC');
        $back = ZonedDateTime::fromDateTime($zdt->toDateTime());

        static::assertSame(1_592_224_245_123_456_000, $back->epochNanoseconds);
        static::assertSame('UTC', $back->timeZoneId);
    }

    public function testFromDateTimeFarFutureBeyondInt64Throws(): void
    {
        $dt = new \DateTimeImmutable('3000-01-01T00:00:00', new \DateTimeZone('UTC'));

        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('outside the representable int64 nanosecond range');

        ZonedDateTime::fromDateTime($dt);
    }

    /**
     * Largest positive epoch second that still fits the int64 nanosecond range
     * (9_223_372_035 × 10⁹ + max-microsecond < PHP_INT_MAX). Pins the upper
     * boundary: this exact value must be accepted, not rejected.
     */
    public function testFromDateTimeMaxRepresentableSecondAccepted(): void
    {
        $dt = new \DateTimeImmutable('@0')
            ->setTimestamp(9_223_372_035)
            ->setTimezone(new \DateTimeZone('UTC'));

        $zdt = ZonedDateTime::fromDateTime($dt);

        static::assertSame(9_223_372_035_000_000_000, $zdt->epochNanoseconds);
    }

    /**
     * One second past the representable upper boundary must throw — guards the
     * `> $tsMax` comparison and the `$tsMax` constant against off-by-one drift.
     */
    public function testFromDateTimeOneSecondPastMaxThrows(): void
    {
        $dt = new \DateTimeImmutable('@0')
            ->setTimestamp(9_223_372_036)
            ->setTimezone(new \DateTimeZone('UTC'));

        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('outside the representable int64 nanosecond range');

        ZonedDateTime::fromDateTime($dt);
    }

    /**
     * Most negative epoch second that still fits the int64 nanosecond range.
     * Pins the lower boundary: this exact value must be accepted.
     */
    public function testFromDateTimeMinRepresentableSecondAccepted(): void
    {
        $dt = new \DateTimeImmutable('@0')
            ->setTimestamp(-9_223_372_035)
            ->setTimezone(new \DateTimeZone('UTC'));

        $zdt = ZonedDateTime::fromDateTime($dt);

        static::assertSame(-9_223_372_035_000_000_000, $zdt->epochNanoseconds);
    }

    /**
     * One second below the representable lower boundary must throw — guards the
     * `< -$tsMax` comparison against an off-by-one (`<=`) drift.
     */
    public function testFromDateTimeOneSecondBelowMinThrows(): void
    {
        $dt = new \DateTimeImmutable('@0')
            ->setTimestamp(-9_223_372_036)
            ->setTimezone(new \DateTimeZone('UTC'));

        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('outside the representable int64 nanosecond range');

        ZonedDateTime::fromDateTime($dt);
    }
}
