<?php

declare(strict_types=1);

namespace Calendrics\Tests\Porcelain;

use Calendrics\Exception\RangeError;
use Calendrics\Spec\Duration;
use Calendrics\Spec\PlainDate;
use Calendrics\Spec\PlainDateTime;
use Calendrics\Spec\PlainMonthDay;
use Calendrics\Spec\PlainYearMonth;
use Calendrics\Spec\ZonedDateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that all Temporal types accept known non-ISO calendar IDs
 * (ECMA-402 calendars) and reject unknown ones.
 */
final class NonIsoCalendarAcceptanceTest extends TestCase
{
    // -------------------------------------------------------------------------
    // PlainDate
    // -------------------------------------------------------------------------

    public function testPlainDateConstructorAcceptsNonIsoCalendar(): void
    {
        $d = new PlainDate(2024, 1, 15, 'hebrew');

        static::assertSame('hebrew', $d->calendarId);
        static::assertSame(2024, $d->isoYear);
        static::assertSame(1, $d->isoMonth);
        static::assertSame(15, $d->isoDay);
    }

    public function testPlainDateConstructorRejectsUnknownCalendar(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('Unknown calendar');
        new PlainDate(2024, 1, 15, 'bogus');
    }

    public function testPlainDateFromStringExtractsCalendar(): void
    {
        $d = PlainDate::from('2024-01-15[u-ca=hebrew]');

        static::assertSame('hebrew', $d->calendarId);
        static::assertSame(2024, $d->isoYear);
    }

    public function testPlainDateFromStringRejectsUnknownCalendar(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('Unknown calendar');
        PlainDate::from('2024-01-15[u-ca=bogus]');
    }

    public function testPlainDateFromStringNoAnnotationDefaultsToIso(): void
    {
        $d = PlainDate::from('2024-01-15');

        static::assertSame('iso8601', $d->calendarId);
    }

    public function testPlainDateFromPropertyBagAcceptsNonIsoCalendar(): void
    {
        $d = PlainDate::from(['year' => 2024, 'month' => 1, 'day' => 15, 'calendar' => 'japanese']);

        static::assertSame('japanese', $d->calendarId);
    }

    public function testPlainDateFromPropertyBagRejectsUnknownCalendar(): void
    {
        $this->expectException(RangeError::class);
        PlainDate::from(['year' => 2024, 'month' => 1, 'day' => 15, 'calendar' => 'bogus']);
    }

    public function testPlainDateWithCalendarAcceptsNonIso(): void
    {
        $d = PlainDate::from('2024-01-15');
        $d2 = $d->withCalendar('hebrew');

        static::assertSame('hebrew', $d2->calendarId);
        static::assertSame(2024, $d2->isoYear);
    }

    public function testPlainDateFromPreservesCalendarId(): void
    {
        $d1 = PlainDate::from('2024-01-15[u-ca=hebrew]');
        $d2 = PlainDate::from($d1);

        static::assertSame('hebrew', $d2->calendarId);
    }

    public function testPlainDateCalendarProjectedProperties(): void
    {
        $d = new PlainDate(2024, 1, 15, 'hebrew');

        // Hebrew year for Jan 15 2024 should be in the 5784 range
        static::assertSame(5784, $d->year);
        // monthCode should be valid
        static::assertNotEmpty($d->monthCode);
    }

    public function testPlainDateToStringIncludesNonIsoCalendar(): void
    {
        $d = new PlainDate(2024, 1, 15, 'hebrew');

        static::assertSame('2024-01-15[u-ca=hebrew]', $d->toString());
    }

    public function testPlainDateToStringOmitsIsoCalendar(): void
    {
        $d = new PlainDate(2024, 1, 15);

        static::assertSame('2024-01-15', $d->toString());
    }

    public function testPlainDateConstructorCanonicalizesCalendar(): void
    {
        $d = new PlainDate(2024, 1, 15, 'HEBREW');

        static::assertSame('hebrew', $d->calendarId);
    }

    public function testPlainDateConstructorResolvesAlias(): void
    {
        $d = new PlainDate(2024, 1, 15, 'islamicc');

        static::assertSame('islamic-civil', $d->calendarId);
    }

    // -------------------------------------------------------------------------
    // PlainDateTime
    // -------------------------------------------------------------------------

    public function testPlainDateTimeConstructorAcceptsNonIsoCalendar(): void
    {
        $dt = new PlainDateTime(2024, 1, 15, 10, 30, 0, 0, 0, 0, 'japanese');

        static::assertSame('japanese', $dt->calendarId);
    }

    public function testPlainDateTimeConstructorRejectsUnknownCalendar(): void
    {
        $this->expectException(RangeError::class);
        new PlainDateTime(2024, 1, 15, 10, 30, 0, 0, 0, 0, 'bogus');
    }

    public function testPlainDateTimeFromStringExtractsCalendar(): void
    {
        $dt = PlainDateTime::from('2024-01-15T10:30:00[u-ca=buddhist]');

        static::assertSame('buddhist', $dt->calendarId);
    }

    public function testPlainDateTimeFromPropertyBagAcceptsCalendar(): void
    {
        $dt = PlainDateTime::from([
            'year' => 2024,
            'month' => 1,
            'day' => 15,
            'hour' => 10,
            'calendar' => 'gregory',
        ]);

        static::assertSame('gregory', $dt->calendarId);
    }

    public function testPlainDateTimeWithCalendarAcceptsNonIso(): void
    {
        $dt = PlainDateTime::from('2024-01-15T10:30:00');
        $dt2 = $dt->withCalendar('persian');

        static::assertSame('persian', $dt2->calendarId);
    }

    public function testPlainDateTimeFromPreservesCalendarId(): void
    {
        $dt1 = PlainDateTime::from('2024-01-15T10:30:00[u-ca=roc]');
        $dt2 = PlainDateTime::from($dt1);

        static::assertSame('roc', $dt2->calendarId);
    }

    // -------------------------------------------------------------------------
    // PlainMonthDay
    // -------------------------------------------------------------------------

    public function testPlainMonthDayConstructorAcceptsNonIsoCalendar(): void
    {
        $md = new PlainMonthDay(3, 15, 'coptic');

        static::assertSame('coptic', $md->calendarId);
    }

    public function testPlainMonthDayConstructorRejectsUnknownCalendar(): void
    {
        $this->expectException(RangeError::class);
        new PlainMonthDay(3, 15, 'bogus');
    }

    public function testPlainMonthDayFromStringExtractsCalendar(): void
    {
        $md = PlainMonthDay::from('1972-03-15[u-ca=chinese]');

        static::assertSame('chinese', $md->calendarId);
    }

    public function testPlainMonthDayFromPropertyBagAcceptsCalendar(): void
    {
        // Per TC39 spec, PlainMonthDay with non-ISO calendar requires either
        // monthCode or year to disambiguate, since plain month numbers may not
        // be stable across years (e.g. leap months).
        $md = PlainMonthDay::from(['monthCode' => 'M03', 'day' => 15, 'calendar' => 'dangi']);

        static::assertSame('dangi', $md->calendarId);
    }

    public function testPlainMonthDayFromPreservesCalendarId(): void
    {
        $md1 = PlainMonthDay::from('1972-03-15[u-ca=indian]');
        $md2 = PlainMonthDay::from($md1);

        static::assertSame('indian', $md2->calendarId);
    }

    // -------------------------------------------------------------------------
    // PlainYearMonth
    // -------------------------------------------------------------------------

    public function testPlainYearMonthConstructorAcceptsNonIsoCalendar(): void
    {
        $ym = new PlainYearMonth(2024, 6, 'ethiopic');

        static::assertSame('ethiopic', $ym->calendarId);
    }

    public function testPlainYearMonthConstructorRejectsUnknownCalendar(): void
    {
        $this->expectException(RangeError::class);
        new PlainYearMonth(2024, 6, 'bogus');
    }

    public function testPlainYearMonthFromStringExtractsCalendar(): void
    {
        $ym = PlainYearMonth::from('2024-06-01[u-ca=islamic-civil]');

        static::assertSame('islamic-civil', $ym->calendarId);
    }

    public function testPlainYearMonthFromPropertyBagAcceptsCalendar(): void
    {
        $ym = PlainYearMonth::from(['year' => 2024, 'month' => 6, 'calendar' => 'persian']);

        static::assertSame('persian', $ym->calendarId);
    }

    public function testPlainYearMonthFromPreservesCalendarId(): void
    {
        $ym1 = PlainYearMonth::from('2024-06-01[u-ca=roc]');
        $ym2 = PlainYearMonth::from($ym1);

        static::assertSame('roc', $ym2->calendarId);
    }

    // -------------------------------------------------------------------------
    // ZonedDateTime
    // -------------------------------------------------------------------------

    public function testZonedDateTimeConstructorAcceptsNonIsoCalendar(): void
    {
        $zdt = new ZonedDateTime(0, 'UTC', 'gregory');

        static::assertSame('gregory', $zdt->calendarId);
    }

    public function testZonedDateTimeConstructorRejectsUnknownCalendar(): void
    {
        $this->expectException(RangeError::class);
        new ZonedDateTime(0, 'UTC', 'bogus');
    }

    public function testZonedDateTimeFromStringExtractsCalendar(): void
    {
        $zdt = ZonedDateTime::from('2024-01-15T10:30:00+00:00[UTC][u-ca=hebrew]');

        static::assertSame('hebrew', $zdt->calendarId);
    }

    public function testZonedDateTimeFromStringRejectsUnknownCalendar(): void
    {
        $this->expectException(RangeError::class);
        ZonedDateTime::from('2024-01-15T10:30:00+00:00[UTC][u-ca=bogus]');
    }

    public function testZonedDateTimeWithCalendarAcceptsNonIso(): void
    {
        $zdt = ZonedDateTime::from('2024-01-15T10:30:00+00:00[UTC]');
        $zdt2 = $zdt->withCalendar('japanese');

        static::assertSame('japanese', $zdt2->calendarId);
    }

    public function testZonedDateTimeFromPropertyBagAcceptsCalendar(): void
    {
        $zdt = ZonedDateTime::from([
            'year' => 2024,
            'month' => 1,
            'day' => 15,
            'timeZone' => 'UTC',
            'calendar' => 'buddhist',
        ]);

        static::assertSame('buddhist', $zdt->calendarId);
    }

    // -------------------------------------------------------------------------
    // Duration relativeTo
    // -------------------------------------------------------------------------

    public function testDurationRelativeToStringAcceptsNonIsoCalendar(): void
    {
        $d = Duration::from('P1Y');

        // Should not throw
        $result = $d->total(['unit' => 'days', 'relativeTo' => '2024-01-15[u-ca=hebrew]']);

        static::assertSame(366.0, (float) $result);
    }

    public function testDurationRelativeToStringRejectsUnknownCalendar(): void
    {
        $d = Duration::from('P1Y');

        $this->expectException(RangeError::class);
        $d->total(['unit' => 'days', 'relativeTo' => '2024-01-15[u-ca=bogus]']);
    }

    public function testDurationRelativeToPropertyBagAcceptsNonIsoCalendar(): void
    {
        $d = Duration::from('P1Y');

        // Should not throw
        $result = $d->total([
            'unit' => 'days',
            'relativeTo' => ['year' => 2024, 'month' => 1, 'day' => 15, 'calendar' => 'hebrew'],
        ]);

        static::assertSame(366.0, (float) $result);
    }

    public function testDurationRelativeToPropertyBagRejectsUnknownCalendar(): void
    {
        $d = Duration::from('P1Y');

        $this->expectException(RangeError::class);
        $d->total([
            'unit' => 'days',
            'relativeTo' => ['year' => 2024, 'month' => 1, 'day' => 15, 'calendar' => 'bogus'],
        ]);
    }

    // -------------------------------------------------------------------------
    // CalendarMath::validateAnnotations
    // -------------------------------------------------------------------------

    public function testValidateAnnotationsReturnsCalendarId(): void
    {
        $result = \Calendrics\Spec\Internal\CalendarMath::validateAnnotations('[u-ca=hebrew]', 'test');

        static::assertSame('hebrew', $result);
    }

    public function testValidateAnnotationsReturnsNullWhenNoCalendar(): void
    {
        $result = \Calendrics\Spec\Internal\CalendarMath::validateAnnotations('[America/New_York]', 'test');

        static::assertNull($result);
    }

    public function testValidateAnnotationsReturnsNullForEmptySection(): void
    {
        $result = \Calendrics\Spec\Internal\CalendarMath::validateAnnotations('', 'test');

        static::assertNull($result);
    }

    public function testValidateAnnotationsRejectsUnknownCalendar(): void
    {
        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('Unknown calendar');
        \Calendrics\Spec\Internal\CalendarMath::validateAnnotations('[u-ca=bogus]', 'test');
    }

    public function testValidateAnnotationsSkipsCalendarCheckWhenDisabled(): void
    {
        // With checkCalendar=false, unknown calendars should be silently ignored
        $result = \Calendrics\Spec\Internal\CalendarMath::validateAnnotations('[u-ca=anything]', 'test', false);

        static::assertNull($result);
    }

    // -------------------------------------------------------------------------
    // extractCalendarFromString
    // -------------------------------------------------------------------------

    public function testExtractCalendarFromStringAcceptsKnownCalendar(): void
    {
        $result = \Calendrics\Spec\Internal\Calendar\CalendarFactory::extractCalendarFromString('hebrew');

        static::assertSame('hebrew', $result);
    }

    public function testExtractCalendarFromStringAcceptsAnnotation(): void
    {
        $result = \Calendrics\Spec\Internal\Calendar\CalendarFactory::extractCalendarFromString(
            '2024-01-15[u-ca=japanese]',
        );

        static::assertSame('japanese', $result);
    }

    public function testExtractCalendarFromStringRejectsUnknownCalendar(): void
    {
        $this->expectException(RangeError::class);
        \Calendrics\Spec\Internal\Calendar\CalendarFactory::extractCalendarFromString('nonsense');
    }

    // -------------------------------------------------------------------------
    // All known calendars accepted
    // -------------------------------------------------------------------------

    #[DataProvider('knownCalendarProvider')]
    public function testAllKnownCalendarsAcceptedByConstructor(string $calendarId): void
    {
        $d = new PlainDate(2024, 1, 15, $calendarId);

        static::assertSame($calendarId, $d->calendarId);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function knownCalendarProvider(): iterable
    {
        $calendars = [
            'iso8601',
            'buddhist',
            'chinese',
            'coptic',
            'dangi',
            'ethioaa',
            'ethiopic',
            'gregory',
            'hebrew',
            'indian',
            'islamic-civil',
            'islamic-tbla',
            'islamic-umalqura',
            'japanese',
            'persian',
            'roc',
        ];

        foreach ($calendars as $cal) {
            yield $cal => [$cal];
        }
    }
}
