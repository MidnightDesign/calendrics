<?php

declare(strict_types=1);

namespace Calendrics\Tests\Porcelain;

use Calendrics\Exception\RangeError;
use Calendrics\Spec\Internal\Calendar\CalendarFactory;
use Calendrics\Spec\PlainDate;
use Calendrics\Spec\PlainDateTime;
use Calendrics\Spec\PlainMonthDay;
use Calendrics\Spec\PlainYearMonth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Phase 4: Calendar field resolution (from/with).
 *
 * Verifies that calendarToIso(), calendarToIsoFromMonthCode(), and monthCodeToMonth()
 * correctly resolve non-ISO calendar fields to ISO fields, and that fromPropertyBag()
 * and with() work correctly for non-ISO calendars.
 */
final class CalendarFieldResolutionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // PlainDate::from() with non-ISO calendars
    // -------------------------------------------------------------------------

    public function testPlainDateFromHebrewPropertyBag(): void
    {
        $d = PlainDate::from(['year' => 5784, 'month' => 5, 'day' => 5, 'calendar' => 'hebrew']);

        static::assertSame('hebrew', $d->calendarId);
        static::assertSame(5784, $d->year);
        static::assertSame(5, $d->month);
        static::assertSame(5, $d->day);
    }

    public function testPlainDateFromGregoryPropertyBag(): void
    {
        $d = PlainDate::from(['year' => 2024, 'month' => 1, 'day' => 15, 'calendar' => 'gregory']);

        static::assertSame('gregory', $d->calendarId);
        static::assertSame(2024, $d->isoYear);
        static::assertSame(1, $d->isoMonth);
        static::assertSame(15, $d->isoDay);
    }

    public function testPlainDateFromBuddhistPropertyBag(): void
    {
        $d = PlainDate::from(['year' => 2567, 'month' => 1, 'day' => 15, 'calendar' => 'buddhist']);

        static::assertSame('buddhist', $d->calendarId);
        static::assertSame(2024, $d->isoYear);
    }

    public function testPlainDateFromPersianPropertyBag(): void
    {
        $d = PlainDate::from(['year' => 1403, 'month' => 1, 'day' => 1, 'calendar' => 'persian']);

        static::assertSame('persian', $d->calendarId);
        static::assertSame(1403, $d->year);
        static::assertSame(1, $d->month);
        static::assertSame(1, $d->day);
    }

    public function testPlainDateFromIslamicCivilPropertyBag(): void
    {
        $d = PlainDate::from(['year' => 1445, 'month' => 1, 'day' => 1, 'calendar' => 'islamic-civil']);

        static::assertSame('islamic-civil', $d->calendarId);
        static::assertSame(1445, $d->year);
    }

    public function testPlainDateFromCopticPropertyBag(): void
    {
        $d = PlainDate::from(['year' => 1740, 'month' => 1, 'day' => 1, 'calendar' => 'coptic']);

        static::assertSame('coptic', $d->calendarId);
        static::assertSame(1740, $d->year);
    }

    public function testPlainDateFromJapanesePropertyBag(): void
    {
        $d = PlainDate::from(['year' => 2024, 'month' => 1, 'day' => 15, 'calendar' => 'japanese']);

        static::assertSame('japanese', $d->calendarId);
        static::assertSame(2024, $d->isoYear);
    }

    // -------------------------------------------------------------------------
    // PlainDate::from() with monthCode
    // -------------------------------------------------------------------------

    public function testPlainDateFromHebrewMonthCode(): void
    {
        $d = PlainDate::from(['year' => 5784, 'monthCode' => 'M05', 'day' => 5, 'calendar' => 'hebrew']);

        static::assertSame('M05', $d->monthCode);
        static::assertSame(5, $d->month);
    }

    public function testPlainDateFromHebrewLeapMonthCode(): void
    {
        // 5784 is a Hebrew leap year: (7*5784+1)%19 = 2 < 7
        $d = PlainDate::from(['year' => 5784, 'monthCode' => 'M05L', 'day' => 5, 'calendar' => 'hebrew']);

        static::assertSame('M05L', $d->monthCode);
        static::assertSame(6, $d->month);
    }

    public function testPlainDateFromHebrewLeapMonthCodeThrowsInNonLeapYear(): void
    {
        // 5783: (7*5783+1)%19 = 14, not < 7, so not a leap year
        $this->expectException(RangeError::class);
        PlainDate::from(['year' => 5783, 'monthCode' => 'M05L', 'day' => 5, 'calendar' => 'hebrew']);
    }

    public function testPlainDateFromCopticM13(): void
    {
        $d = PlainDate::from(['year' => 1740, 'monthCode' => 'M13', 'day' => 5, 'calendar' => 'coptic']);

        static::assertSame(13, $d->month);
        static::assertSame('M13', $d->monthCode);
    }

    // -------------------------------------------------------------------------
    // PlainDate roundtrip: ISO -> calendar -> property bag -> ISO
    // -------------------------------------------------------------------------

    #[DataProvider('roundtripCalendarProvider')]
    public function testPlainDateRoundtrip(string $calendarId): void
    {
        $iso = PlainDate::from('2024-06-15');
        $cal = $iso->withCalendar($calendarId);

        $back = PlainDate::from([
            'year' => $cal->year,
            'month' => $cal->month,
            'day' => $cal->day,
            'calendar' => $calendarId,
        ]);

        static::assertSame($iso->isoYear, $back->isoYear, "isoYear roundtrip for {$calendarId}");
        static::assertSame($iso->isoMonth, $back->isoMonth, "isoMonth roundtrip for {$calendarId}");
        static::assertSame($iso->isoDay, $back->isoDay, "isoDay roundtrip for {$calendarId}");
    }

    #[DataProvider('roundtripCalendarProvider')]
    public function testPlainDateRoundtripWithMonthCode(string $calendarId): void
    {
        $iso = PlainDate::from('2024-06-15');
        $cal = $iso->withCalendar($calendarId);

        $back = PlainDate::from([
            'year' => $cal->year,
            'monthCode' => $cal->monthCode,
            'day' => $cal->day,
            'calendar' => $calendarId,
        ]);

        static::assertSame($iso->isoYear, $back->isoYear, "isoYear roundtrip for {$calendarId}");
        static::assertSame($iso->isoMonth, $back->isoMonth, "isoMonth roundtrip for {$calendarId}");
        static::assertSame($iso->isoDay, $back->isoDay, "isoDay roundtrip for {$calendarId}");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function roundtripCalendarProvider(): iterable
    {
        $calendars = [
            'buddhist',
            'coptic',
            'ethiopic',
            'ethioaa',
            'gregory',
            'hebrew',
            'indian',
            'islamic-civil',
            'islamic-tbla',
            'islamic-umalqura',
            'japanese',
            'persian',
            'roc',
            'chinese',
            'dangi',
        ];
        foreach ($calendars as $cal) {
            yield $cal => [$cal];
        }
    }

    // -------------------------------------------------------------------------
    // PlainDate::with() for non-ISO calendars
    // -------------------------------------------------------------------------

    public function testPlainDateWithDayOnHebrewCalendar(): void
    {
        $d = PlainDate::from('2024-01-15')->withCalendar('hebrew');

        $d2 = $d->with(['day' => 10]);

        static::assertSame(10, $d2->day);
        static::assertSame($d->year, $d2->year);
        static::assertSame($d->month, $d2->month);
    }

    public function testPlainDateWithMonthOnHebrewCalendar(): void
    {
        $d = PlainDate::from('2024-01-15')->withCalendar('hebrew');

        $d2 = $d->with(['month' => 6]);

        static::assertSame(6, $d2->month);
        static::assertSame($d->year, $d2->year);
    }

    public function testPlainDateWithMonthCodeOnHebrewCalendar(): void
    {
        $d = PlainDate::from('2024-01-15')->withCalendar('hebrew');

        $d2 = $d->with(['monthCode' => 'M06']);

        static::assertSame('M06', $d2->monthCode);
    }

    public function testPlainDateWithYearOnGregoryCalendar(): void
    {
        $d = PlainDate::from('2024-06-15')->withCalendar('gregory');

        $d2 = $d->with(['year' => 2020]);

        static::assertSame(2020, $d2->year);
        static::assertSame($d->month, $d2->month);
        static::assertSame($d->day, $d2->day);
    }

    // -------------------------------------------------------------------------
    // Era resolution in from()
    // -------------------------------------------------------------------------

    public function testPlainDateFromJapaneseWithEra(): void
    {
        $d = PlainDate::from([
            'calendar' => 'japanese',
            'era' => 'ce',
            'eraYear' => 1800,
            'month' => 6,
            'day' => 15,
        ]);

        static::assertSame(1800, $d->year);
        static::assertSame('ce', $d->era);
        static::assertSame(1800, $d->eraYear);
    }

    public function testPlainDateFromJapaneseBce(): void
    {
        $d = PlainDate::from([
            'calendar' => 'japanese',
            'era' => 'bce',
            'eraYear' => 100,
            'month' => 1,
            'day' => 1,
        ]);

        static::assertSame(-99, $d->year);
        static::assertSame('bce', $d->era);
        static::assertSame(100, $d->eraYear);
    }

    public function testPlainDateFromInvalidEraThrows(): void
    {
        $this->expectException(RangeError::class);
        PlainDate::from([
            'year' => 2025,
            'month' => 1,
            'day' => 1,
            'era' => 'xyz',
            'eraYear' => 2025,
            'calendar' => 'japanese',
        ]);
    }

    public function testPlainDateFromChineseIgnoresEra(): void
    {
        // Era should be silently ignored for Chinese calendar
        $d = PlainDate::from([
            'year' => 2025,
            'month' => 1,
            'day' => 1,
            'era' => 'xyz',
            'eraYear' => 2025,
            'calendar' => 'chinese',
        ]);

        static::assertInstanceOf(PlainDate::class, $d); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    // -------------------------------------------------------------------------
    // Era property values
    // -------------------------------------------------------------------------

    public function testEraValuesForAllCalendars(): void
    {
        $d = PlainDate::from('2024-01-15');

        static::assertSame('ce', $d->withCalendar('gregory')->era);
        static::assertSame('be', $d->withCalendar('buddhist')->era);
        static::assertSame('roc', $d->withCalendar('roc')->era);
        static::assertSame('am', $d->withCalendar('coptic')->era);
        static::assertSame('am', $d->withCalendar('hebrew')->era);
        static::assertSame('shaka', $d->withCalendar('indian')->era);
        static::assertSame('ah', $d->withCalendar('islamic-civil')->era);
        static::assertSame('ap', $d->withCalendar('persian')->era);
        static::assertSame('aa', $d->withCalendar('ethioaa')->era);
    }

    // -------------------------------------------------------------------------
    // PlainDateTime::from() with non-ISO calendars
    // -------------------------------------------------------------------------

    public function testPlainDateTimeFromHebrewPropertyBag(): void
    {
        $dt = PlainDateTime::from([
            'year' => 5784,
            'month' => 5,
            'day' => 5,
            'hour' => 10,
            'calendar' => 'hebrew',
        ]);

        static::assertSame('hebrew', $dt->calendarId);
        static::assertSame(5784, $dt->year);
        static::assertSame(5, $dt->month);
        static::assertSame(5, $dt->day);
        static::assertSame(10, $dt->hour);
    }

    public function testPlainDateTimeFromGregoryPropertyBag(): void
    {
        $dt = PlainDateTime::from([
            'year' => 2024,
            'month' => 1,
            'day' => 15,
            'hour' => 12,
            'minute' => 30,
            'calendar' => 'gregory',
        ]);

        static::assertSame('gregory', $dt->calendarId);
        static::assertSame(2024, $dt->isoYear);
        static::assertSame(12, $dt->hour);
    }

    // -------------------------------------------------------------------------
    // PlainDateTime::with() for non-ISO calendars
    // -------------------------------------------------------------------------

    public function testPlainDateTimeWithDayOnPersianCalendar(): void
    {
        $dt = PlainDateTime::from('2024-01-15T10:30:00')->withCalendar('persian');

        $dt2 = $dt->with(['day' => 1]);

        static::assertSame(1, $dt2->day);
        static::assertSame($dt->year, $dt2->year);
        static::assertSame($dt->month, $dt2->month);
        static::assertSame(10, $dt2->hour);
        static::assertSame(30, $dt2->minute);
    }

    // -------------------------------------------------------------------------
    // PlainYearMonth::from() with non-ISO calendars
    // -------------------------------------------------------------------------

    public function testPlainYearMonthFromHebrewPropertyBag(): void
    {
        $ym = PlainYearMonth::from(['year' => 5784, 'month' => 5, 'calendar' => 'hebrew']);

        static::assertSame('hebrew', $ym->calendarId);
        static::assertSame(5784, $ym->year);
        static::assertSame(5, $ym->month);
    }

    public function testPlainYearMonthFromBuddhistPropertyBag(): void
    {
        $ym = PlainYearMonth::from(['year' => 2567, 'month' => 1, 'calendar' => 'buddhist']);

        static::assertSame('buddhist', $ym->calendarId);
        static::assertSame(2024, $ym->isoYear);
    }

    // -------------------------------------------------------------------------
    // PlainYearMonth::with() for non-ISO calendars
    // -------------------------------------------------------------------------

    public function testPlainYearMonthWithOnHebrewCalendar(): void
    {
        $ym = PlainYearMonth::from('2024-01-01[u-ca=hebrew]');

        $ym2 = $ym->with(['month' => 6]);

        static::assertSame(6, $ym2->month);
        static::assertSame($ym->year, $ym2->year);
    }

    // -------------------------------------------------------------------------
    // PlainMonthDay::from() with non-ISO calendars
    // -------------------------------------------------------------------------

    public function testPlainMonthDayFromHebrewPropertyBag(): void
    {
        $md = PlainMonthDay::from([
            'year' => 5784,
            'monthCode' => 'M05',
            'day' => 15,
            'calendar' => 'hebrew',
        ]);

        static::assertSame('hebrew', $md->calendarId);
        static::assertSame('M05', $md->monthCode);
    }

    public function testPlainMonthDayFromRejectsConflictingMonthAndMonthCode(): void
    {
        $this->expectException(RangeError::class);
        PlainMonthDay::from([
            'year' => 2024,
            'monthCode' => 'M01',
            'month' => 2,
            'day' => 1,
            'calendar' => 'gregory',
        ]);
    }

    public function testPlainMonthDayWithRejectsConflictingMonthAndMonthCode(): void
    {
        $md = PlainMonthDay::from([
            'year' => 2024,
            'monthCode' => 'M01',
            'day' => 1,
            'calendar' => 'gregory',
        ]);

        $this->expectException(RangeError::class);
        $md->with(['year' => 2024, 'monthCode' => 'M01', 'month' => 2]);
    }

    public function testPlainMonthDayWithAcceptsAgreeingMonthAndMonthCode(): void
    {
        $md = PlainMonthDay::from([
            'year' => 2024,
            'monthCode' => 'M01',
            'day' => 1,
            'calendar' => 'gregory',
        ]);

        $updated = $md->with(['year' => 2024, 'monthCode' => 'M03', 'month' => 3]);

        static::assertSame('M03', $updated->monthCode);
    }

    // -------------------------------------------------------------------------
    // Overflow handling
    // -------------------------------------------------------------------------

    public function testPlainDateFromConstrainsOverflowingDay(): void
    {
        // Hebrew month may have fewer days than 30
        $d = PlainDate::from([
            'year' => 5784,
            'month' => 1,
            'day' => 50,
            'calendar' => 'hebrew',
        ]);

        // Day should be constrained to the maximum for that month
        static::assertLessThanOrEqual(30, $d->day);
    }

    public function testPlainDateFromRejectsOverflowingDay(): void
    {
        $this->expectException(RangeError::class);
        PlainDate::from([
            'year' => 5784,
            'month' => 1,
            'day' => 50,
            'calendar' => 'hebrew',
        ], ['overflow' => 'reject']);
    }

    // -------------------------------------------------------------------------
    // monthCodeToMonth
    // -------------------------------------------------------------------------

    public function testMonthCodeToMonthForStandardCalendar(): void
    {
        $cal = CalendarFactory::get('gregory');

        static::assertSame(1, $cal->monthCodeToMonth('M01', 2024));
        static::assertSame(12, $cal->monthCodeToMonth('M12', 2024));
    }

    public function testMonthCodeToMonthForCoptic13(): void
    {
        $cal = CalendarFactory::get('coptic');

        static::assertSame(13, $cal->monthCodeToMonth('M13', 1740));
    }

    public function testMonthCodeToMonthForHebrewLeap(): void
    {
        $cal = CalendarFactory::get('hebrew');

        // 5784 is a leap year
        static::assertSame(6, $cal->monthCodeToMonth('M05L', 5784));
    }

    public function testMonthCodeToMonthForHebrewLeapThrowsInNonLeapYear(): void
    {
        $cal = CalendarFactory::get('hebrew');

        $this->expectException(RangeError::class);
        $cal->monthCodeToMonth('M05L', 5783);
    }

    public function testMonthCodeToMonthInvalidCodeThrows(): void
    {
        $cal = CalendarFactory::get('gregory');

        $this->expectException(RangeError::class);
        $cal->monthCodeToMonth('M13', 2024);
    }

    // -------------------------------------------------------------------------
    // resolveEra
    // -------------------------------------------------------------------------

    public function testResolveEraGregory(): void
    {
        $cal = CalendarFactory::get('gregory');

        static::assertSame(2024, $cal->resolveEra('ce', 2024));
        static::assertSame(-99, $cal->resolveEra('bce', 100));
    }

    public function testResolveEraJapanese(): void
    {
        $cal = CalendarFactory::get('japanese');

        static::assertSame(2024, $cal->resolveEra('reiwa', 6));
        static::assertSame(2019, $cal->resolveEra('heisei', 31));
        static::assertSame(1800, $cal->resolveEra('ce', 1800));
        static::assertSame(-99, $cal->resolveEra('bce', 100));
    }

    public function testResolveEraChineseReturnsNull(): void
    {
        $cal = CalendarFactory::get('chinese');

        static::assertNull($cal->resolveEra('anything', 2024));
    }

    public function testResolveEraInvalidThrows(): void
    {
        $cal = CalendarFactory::get('gregory');

        $this->expectException(RangeError::class);
        $cal->resolveEra('xyz', 2024);
    }

    public function testResolveEraIsoCalendarReturnsNull(): void
    {
        $cal = CalendarFactory::get('iso8601');

        static::assertNull($cal->resolveEra('anything', 2024));
    }
}
