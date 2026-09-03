<?php

declare(strict_types=1);

namespace Calendrics\Tests\Porcelain;

use Calendrics\Exception\RangeError;
use Calendrics\Spec\Duration;
use Calendrics\Spec\Internal\Calendar\CalendarFactory;
use Calendrics\Spec\PlainDate;
use Calendrics\Spec\PlainDateTime;
use Calendrics\Spec\PlainYearMonth;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Phase 5: Calendar arithmetic (dateAdd / dateUntil).
 *
 * Verifies that add/subtract and since/until work correctly for non-ISO
 * calendars by delegating through the calendar protocol (IntlCalendarBridge).
 */
final class CalendarArithmeticTest extends TestCase
{
    // =========================================================================
    // IntlCalendarBridge::dateAdd — direct protocol tests
    // =========================================================================

    public function testDateAddBuddhistOneYear(): void
    {
        $cal = CalendarFactory::get('buddhist');
        // 2024-01-15 in Buddhist is year 2567
        [$isoY, $isoM, $isoD] = $cal->dateAdd(2024, 1, 15, 1, 0, 0, 0, 'constrain');

        static::assertSame(2025, $isoY);
        static::assertSame(1, $isoM);
        static::assertSame(15, $isoD);
    }

    public function testDateAddHebrewOneMonth(): void
    {
        $cal = CalendarFactory::get('hebrew');
        // 2024-01-15 is Hebrew 5784 Tevet 5. Adding 1 month goes to Shevat.
        [$isoY, $isoM, $isoD] = $cal->dateAdd(2024, 1, 15, 0, 1, 0, 0, 'constrain');

        // Verify the result is a valid date about 29-30 days later
        $d1 = PlainDate::from('2024-01-15[u-ca=hebrew]');
        $d2 = new PlainDate($isoY, $isoM, $isoD, 'hebrew');
        static::assertSame($d1->year, $d2->year);
        static::assertSame($d1->month + 1, $d2->month);
    }

    public function testDateAddPersianOneYear(): void
    {
        $cal = CalendarFactory::get('persian');
        [$isoY, $isoM, $isoD] = $cal->dateAdd(2024, 3, 20, 1, 0, 0, 0, 'constrain');

        // 2024-03-20 is ~Farvardin 1, 1403. +1 year → Farvardin 1, 1404
        $d = new PlainDate($isoY, $isoM, $isoD, 'persian');
        static::assertSame(1404, $d->year);
        static::assertSame(1, $d->month);
    }

    public function testDateAddGregoryNegativeMonths(): void
    {
        $cal = CalendarFactory::get('gregory');
        [$isoY, $isoM, $isoD] = $cal->dateAdd(2024, 3, 15, 0, -2, 0, 0, 'constrain');

        static::assertSame(2024, $isoY);
        static::assertSame(1, $isoM);
        static::assertSame(15, $isoD);
    }

    public function testDateAddDaysOnly(): void
    {
        $cal = CalendarFactory::get('hebrew');
        [$isoY, $isoM, $isoD] = $cal->dateAdd(2024, 1, 15, 0, 0, 0, 10, 'constrain');

        static::assertSame(2024, $isoY);
        static::assertSame(1, $isoM);
        static::assertSame(25, $isoD);
    }

    public function testDateAddWeeks(): void
    {
        $cal = CalendarFactory::get('buddhist');
        [$isoY, $isoM, $isoD] = $cal->dateAdd(2024, 1, 15, 0, 0, 2, 0, 'constrain');

        static::assertSame(2024, $isoY);
        static::assertSame(1, $isoM);
        static::assertSame(29, $isoD);
    }

    public function testDateAddRejectOverflow(): void
    {
        CalendarFactory::get('hebrew');
        // Adding years/months that reduce the day should throw for 'reject'.
        // Hebrew month Adar (month 12 / ICU month 12) has 29 days in non-leap years.
        // Create a date on day 30 of a 30-day month, then add months to land on a 29-day month.
        // Use ISO: 2024-01-15 is Hebrew Tevet 5. Tevet has 29 days.
        // Start from a month with 30 days (e.g., Kislev), day 30.
        // Hebrew Kislev (month 3) can have 29 or 30 days.

        // Simpler: use gregory calendar, add 1 month from Jan 31 to Feb.
        $cal2 = CalendarFactory::get('gregory');
        $this->expectException(RangeError::class);
        $cal2->dateAdd(2024, 1, 31, 0, 1, 0, 0, 'reject');
    }

    public function testDateAddConstrainOverflow(): void
    {
        $cal = CalendarFactory::get('gregory');
        // Jan 31 + 1 month = Feb 29 (2024 is a leap year), constrained from 31.
        [$isoY, $isoM, $isoD] = $cal->dateAdd(2024, 1, 31, 0, 1, 0, 0, 'constrain');

        static::assertSame(2024, $isoY);
        static::assertSame(2, $isoM);
        static::assertSame(29, $isoD);
    }

    // =========================================================================
    // dateUntil — direct CalendarProtocol tests. Negative and same-date cases
    // (buddhist/persian) exercise IntlCalendarBridge; the month/year cases
    // below use PureHebrewCalendar via 'hebrew'.
    // =========================================================================

    public function testDateUntilHebrewMonthUnit(): void
    {
        $cal = CalendarFactory::get('hebrew');
        [$y, $m, $w] = $cal->dateUntil(2024, 1, 15, 2024, 6, 15, 'month');

        static::assertSame(0, $y);
        static::assertGreaterThan(0, $m);
        static::assertSame(0, $w);
    }

    public function testDateUntilHebrewYearUnit(): void
    {
        $cal = CalendarFactory::get('hebrew');
        // One Hebrew year apart
        [$y, $m, $w] = $cal->dateUntil(2023, 9, 16, 2024, 10, 3, 'year');

        static::assertSame(1, $y);
        static::assertSame(0, $m);
        static::assertSame(0, $w);
    }

    public function testDateUntilNegative(): void
    {
        $cal = CalendarFactory::get('buddhist');
        [$y, $m, $w, $d] = $cal->dateUntil(2024, 6, 15, 2024, 1, 15, 'month');

        static::assertSame(0, $y);
        static::assertSame(-5, $m);
        static::assertSame(0, $w);
        static::assertSame(0, $d);
    }

    public function testDateUntilSameDate(): void
    {
        $cal = CalendarFactory::get('persian');
        [$y, $m, $w, $d] = $cal->dateUntil(2024, 3, 20, 2024, 3, 20, 'year');

        static::assertSame(0, $y);
        static::assertSame(0, $m);
        static::assertSame(0, $w);
        static::assertSame(0, $d);
    }

    // =========================================================================
    // PlainDate: add/subtract with non-ISO calendars
    // =========================================================================

    public function testPlainDateAddHebrewOneMonth(): void
    {
        $d = PlainDate::from('2024-01-15[u-ca=hebrew]');
        $d2 = $d->add(new Duration(0, 1));

        static::assertSame('hebrew', $d2->calendarId);
        static::assertSame($d->year, $d2->year);
        static::assertSame($d->month + 1, $d2->month);
    }

    public function testPlainDateAddBuddhistOneYear(): void
    {
        $d = PlainDate::from('2024-01-15[u-ca=buddhist]');
        $d2 = $d->add(new Duration(1));

        static::assertSame('buddhist', $d2->calendarId);
        static::assertSame(2568, $d2->year);
        static::assertSame(2025, $d2->isoYear);
    }

    public function testPlainDateSubtractPersianMonths(): void
    {
        $d = PlainDate::from('2024-06-15[u-ca=persian]');
        $d2 = $d->subtract(new Duration(0, 3));

        static::assertSame('persian', $d2->calendarId);
        // Should go back 3 Persian months
        $diff = $d2->until($d, ['largestUnit' => 'month']);
        static::assertSame(3, $diff->months);
    }

    public function testPlainDateAddPreservesCalendarId(): void
    {
        $d = PlainDate::from('2024-01-15[u-ca=japanese]');
        $d2 = $d->add(new Duration(0, 0, 1));

        static::assertSame('japanese', $d2->calendarId);
    }

    public function testPlainDateAddIsoStillWorks(): void
    {
        $d = PlainDate::from('2024-01-15');
        $d2 = $d->add(new Duration(1, 2, 0, 3));

        static::assertSame('iso8601', $d2->calendarId);
        static::assertSame(2025, $d2->isoYear);
        static::assertSame(3, $d2->isoMonth);
        static::assertSame(18, $d2->isoDay);
    }

    public function testPlainDateAddDaysNonIso(): void
    {
        $d = PlainDate::from('2024-01-15[u-ca=hebrew]');
        $d2 = $d->add(new Duration(0, 0, 0, 10));

        static::assertSame('hebrew', $d2->calendarId);
        static::assertSame(2024, $d2->isoYear);
        static::assertSame(1, $d2->isoMonth);
        static::assertSame(25, $d2->isoDay);
    }

    public function testPlainDateAddRejectOverflowNonIso(): void
    {
        // Gregory: Jan 31 + 1 month with reject should throw
        $d = new PlainDate(2024, 1, 31, 'gregory');
        $this->expectException(RangeError::class);
        $d->add(new Duration(0, 1), ['overflow' => 'reject']);
    }

    // =========================================================================
    // PlainDate: since/until with non-ISO calendars
    // =========================================================================

    public function testPlainDateUntilHebrewMonths(): void
    {
        $a = PlainDate::from('2024-01-15[u-ca=hebrew]');
        $b = PlainDate::from('2024-06-15[u-ca=hebrew]');
        $diff = $a->until($b, ['largestUnit' => 'month']);

        static::assertGreaterThan(0, $diff->months);
        static::assertGreaterThanOrEqual(0, $diff->days);
    }

    public function testPlainDateSinceHebrewMonths(): void
    {
        $a = PlainDate::from('2024-01-15[u-ca=hebrew]');
        $b = PlainDate::from('2024-06-15[u-ca=hebrew]');
        $diff = $b->since($a, ['largestUnit' => 'month']);

        static::assertGreaterThan(0, $diff->months);
    }

    public function testPlainDateUntilBuddhistYear(): void
    {
        $a = PlainDate::from('2024-01-15[u-ca=buddhist]');
        $b = PlainDate::from('2025-01-15[u-ca=buddhist]');
        $diff = $a->until($b, ['largestUnit' => 'year']);

        static::assertSame(1, $diff->years);
        static::assertSame(0, $diff->months);
        static::assertSame(0, $diff->days);
    }

    public function testPlainDateUntilIsoDays(): void
    {
        // Day-level diffs don't need calendar delegation; verify they still work.
        $a = PlainDate::from('2024-01-15[u-ca=hebrew]');
        $b = PlainDate::from('2024-06-15[u-ca=hebrew]');
        $diff = $a->until($b, ['largestUnit' => 'day']);

        static::assertSame(152, $diff->days);
    }

    public function testPlainDateUntilIsoStillWorks(): void
    {
        $a = PlainDate::from('2024-01-15');
        $b = PlainDate::from('2024-06-15');
        $diff = $a->until($b, ['largestUnit' => 'month']);

        static::assertSame(5, $diff->months);
        static::assertSame(0, $diff->days);
    }

    public function testPlainDateRoundTripAddUntilNonIso(): void
    {
        // Adding a duration and computing the diff should be consistent.
        $d = PlainDate::from('2024-03-15[u-ca=persian]');
        $dur = new Duration(1, 3);
        $d2 = $d->add($dur);
        $roundTrip = $d->until($d2, ['largestUnit' => 'year']);

        static::assertSame(1, $roundTrip->years);
        static::assertSame(3, $roundTrip->months);
        static::assertSame(0, $roundTrip->days);
    }

    // =========================================================================
    // PlainDateTime: add/subtract with non-ISO calendars
    // =========================================================================

    public function testPlainDateTimeAddHebrewOneMonth(): void
    {
        $dt = PlainDateTime::from('2024-01-15T10:30:00[u-ca=hebrew]');
        $dt2 = $dt->add(new Duration(0, 1));

        static::assertSame('hebrew', $dt2->calendarId);
        static::assertSame(10, $dt2->hour);
        static::assertSame(30, $dt2->minute);
    }

    public function testPlainDateTimeAddBuddhistOneYear(): void
    {
        $dt = PlainDateTime::from('2024-01-15T08:00:00[u-ca=buddhist]');
        $dt2 = $dt->add(new Duration(1));

        static::assertSame('buddhist', $dt2->calendarId);
        static::assertSame(2568, $dt2->year);
        static::assertSame(2025, $dt2->isoYear);
        static::assertSame(8, $dt2->hour);
    }

    public function testPlainDateTimeAddPreservesCalendarId(): void
    {
        $dt = PlainDateTime::from('2024-01-15T10:00:00[u-ca=japanese]');
        $dt2 = $dt->add(new Duration(0, 0, 1));

        static::assertSame('japanese', $dt2->calendarId);
    }

    public function testPlainDateTimeAddIsoStillWorks(): void
    {
        $dt = PlainDateTime::from('2024-01-15T10:30:00');
        $dt2 = $dt->add(new Duration(0, 1, 0, 0, 2));

        static::assertSame('iso8601', $dt2->calendarId);
        static::assertSame(2, $dt2->isoMonth);
        static::assertSame(12, $dt2->hour);
    }

    public function testPlainDateTimeSinceBuddhistMonths(): void
    {
        $a = PlainDateTime::from('2024-01-15T10:00:00[u-ca=buddhist]');
        $b = PlainDateTime::from('2024-06-15T10:00:00[u-ca=buddhist]');
        $diff = $b->since($a, ['largestUnit' => 'month']);

        static::assertSame(5, $diff->months);
    }

    public function testPlainDateTimeUntilHebrewYear(): void
    {
        $a = PlainDateTime::from('2024-01-15T12:00:00[u-ca=hebrew]');
        $b = PlainDateTime::from('2025-01-15T12:00:00[u-ca=hebrew]');
        $diff = $a->until($b, ['largestUnit' => 'year']);

        // Should be roughly 1 year in the Hebrew calendar
        static::assertGreaterThanOrEqual(0, $diff->years);
    }

    // =========================================================================
    // PlainYearMonth: add/subtract with non-ISO calendars
    // =========================================================================

    public function testPlainYearMonthAddBuddhistOneYear(): void
    {
        $ym = PlainYearMonth::from('2024-01-01[u-ca=buddhist]');
        $ym2 = $ym->add(new Duration(1));

        static::assertSame('buddhist', $ym2->calendarId);
        static::assertSame(2568, $ym2->year);
        static::assertSame(2025, $ym2->isoYear);
    }

    public function testPlainYearMonthAddHebrewMonths(): void
    {
        $ym = PlainYearMonth::from('2024-01-01[u-ca=hebrew]');
        $ym2 = $ym->add(new Duration(0, 3));

        static::assertSame('hebrew', $ym2->calendarId);
        // After adding 3 months, the month should advance
        $calOrig = CalendarFactory::get('hebrew');
        $origMonth = $calOrig->month($ym->isoYear, $ym->isoMonth, 1);
        $newMonth = $calOrig->month($ym2->isoYear, $ym2->isoMonth, $ym2->referenceISODay);
        static::assertGreaterThan($origMonth, $newMonth);
    }

    public function testPlainYearMonthAddPreservesCalendarId(): void
    {
        $ym = PlainYearMonth::from('2024-06-01[u-ca=persian]');
        $ym2 = $ym->add(new Duration(0, 1));

        static::assertSame('persian', $ym2->calendarId);
    }

    public function testPlainYearMonthAddIsoStillWorks(): void
    {
        $ym = PlainYearMonth::from('2024-01');
        $ym2 = $ym->add(new Duration(1, 3));

        static::assertSame('iso8601', $ym2->calendarId);
        static::assertSame(2025, $ym2->isoYear);
        static::assertSame(4, $ym2->isoMonth);
    }

    public function testPlainYearMonthSubtractNonIso(): void
    {
        $ym = PlainYearMonth::from('2024-06-01[u-ca=buddhist]');
        $ym2 = $ym->subtract(new Duration(0, 3));

        static::assertSame('buddhist', $ym2->calendarId);
        static::assertSame(2024, $ym2->isoYear);
        static::assertSame(3, $ym2->isoMonth);
    }

    // =========================================================================
    // PlainYearMonth: since/until with non-ISO calendars
    // =========================================================================

    public function testPlainYearMonthUntilBuddhistYear(): void
    {
        $a = PlainYearMonth::from('2024-01-01[u-ca=buddhist]');
        $b = PlainYearMonth::from('2025-01-01[u-ca=buddhist]');
        $diff = $a->until($b);

        static::assertSame(1, $diff->years);
        static::assertSame(0, $diff->months);
    }

    public function testPlainYearMonthSinceBuddhistMonths(): void
    {
        $a = PlainYearMonth::from('2024-01-01[u-ca=buddhist]');
        $b = PlainYearMonth::from('2024-06-01[u-ca=buddhist]');
        $diff = $b->since($a, ['largestUnit' => 'month']);

        static::assertSame(5, $diff->months);
    }

    public function testPlainYearMonthUntilIsoStillWorks(): void
    {
        $a = PlainYearMonth::from('2024-01');
        $b = PlainYearMonth::from('2025-07');
        $diff = $a->until($b);

        static::assertSame(1, $diff->years);
        static::assertSame(6, $diff->months);
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testDateAddHebrewLeapYearMonth(): void
    {
        // Hebrew leap year has 13 months. Adding months should cross the Adar I gap.
        // Hebrew year 5784 (2023-09-16 to 2024-10-02) is a leap year.
        CalendarFactory::get('hebrew');

        // Start from Hebrew 5784 Adar I (month 6 in leap year, ICU month 5)
        // which is roughly Feb 2024.
        $d = PlainDate::from(['year' => 5784, 'monthCode' => 'M05', 'day' => 1, 'calendar' => 'hebrew']);
        $d2 = $d->add(new Duration(0, 2)); // +2 months: should go past Adar I into Adar II + 1

        static::assertSame('hebrew', $d2->calendarId);
        static::assertSame($d->month + 2, $d2->month);
    }

    public function testPlainDateAddOutOfRangeThrows(): void
    {
        $d = new PlainDate(275_760, 9, 1, 'buddhist');
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('outside the representable range');
        $d->add(new Duration(1));
    }

    public function testDateUntilRoundTripConsistency(): void
    {
        // dateAdd then dateUntil should be consistent for multiple calendars.
        $calendars = ['gregory', 'buddhist', 'persian'];

        foreach ($calendars as $calId) {
            $cal = CalendarFactory::get($calId);
            [$isoY, $isoM, $isoD] = $cal->dateAdd(2024, 3, 15, 1, 2, 0, 0, 'constrain');
            [$y, $m, , $d] = $cal->dateUntil(2024, 3, 15, $isoY, $isoM, $isoD, 'year');

            static::assertSame(1, $y, "{$calId}: round-trip years");
            static::assertSame(2, $m, "{$calId}: round-trip months");
            static::assertSame(0, $d, "{$calId}: round-trip days");
        }
    }
}
