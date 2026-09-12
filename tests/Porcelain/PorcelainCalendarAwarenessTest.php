<?php

declare(strict_types=1);

namespace Calendrics\Tests\Porcelain;

use Calendrics\Calendar;
use Calendrics\Duration;
use Calendrics\PlainDate;
use Calendrics\PlainDateTime;
use Calendrics\PlainMonthDay;
use Calendrics\PlainYearMonth;
use Calendrics\Unit;
use Calendrics\ZonedDateTime;
use PHPUnit\Framework\TestCase;

/**
 * Tests that the porcelain layer correctly preserves and exposes calendar IDs
 * and that fromSpec() round-trips non-ISO calendars without corruption.
 */
final class PorcelainCalendarAwarenessTest extends TestCase
{
    // =========================================================================
    // PlainDate
    // =========================================================================

    public function testPlainDateConstructorAcceptsCalendar(): void
    {
        $pd = new PlainDate(2024, 1, 15, Calendar::Hebrew);

        static::assertSame(Calendar::Hebrew, $pd->calendar);
    }

    public function testPlainDateConstructorDefaultsToIso(): void
    {
        $pd = new PlainDate(2024, 1, 15);

        static::assertSame(Calendar::Iso8601, $pd->calendar);
    }

    public function testPlainDateYearMonthDayAreCalendarProjected(): void
    {
        // 2024-01-15 ISO corresponds to Hebrew year 5784
        $pd = new PlainDate(2024, 1, 15, Calendar::Hebrew);

        static::assertSame(5784, $pd->year);
        static::assertNotSame(2024, $pd->year);
    }

    public function testPlainDateIsoYearMonthDayRoundTrip(): void
    {
        $pd = new PlainDate(2024, 1, 15, Calendar::Hebrew);

        $spec = $pd->toSpec();
        static::assertSame(2024, $spec->isoYear);
        static::assertSame(1, $spec->isoMonth);
        static::assertSame(15, $spec->isoDay);
        static::assertSame('hebrew', $spec->calendarId);
    }

    public function testPlainDateFromSpecPreservesCalendar(): void
    {
        $spec = new \Calendrics\Spec\PlainDate(2024, 1, 15, 'hebrew');
        $pd = PlainDate::fromSpec($spec);

        static::assertSame(Calendar::Hebrew, $pd->calendar);
        // The porcelain should round-trip ISO fields faithfully
        static::assertSame(2024, $pd->toSpec()->isoYear);
        static::assertSame(1, $pd->toSpec()->isoMonth);
        static::assertSame(15, $pd->toSpec()->isoDay);
    }

    public function testPlainDateFromSpecDoesNotCorruptNonIsoCalendar(): void
    {
        // Create a spec PlainDate with hebrew calendar
        $spec1 = new \Calendrics\Spec\PlainDate(2024, 1, 15, 'hebrew');
        $pd = PlainDate::fromSpec($spec1);

        // Round-trip: fromSpec -> toSpec -> fromSpec
        $spec2 = $pd->toSpec();
        $pd2 = PlainDate::fromSpec($spec2);

        // All ISO fields must be identical after round-trip
        static::assertSame($spec1->isoYear, $pd2->toSpec()->isoYear);
        static::assertSame($spec1->isoMonth, $pd2->toSpec()->isoMonth);
        static::assertSame($spec1->isoDay, $pd2->toSpec()->isoDay);
        static::assertSame(Calendar::Hebrew, $pd2->calendar);
    }

    public function testPlainDateParsePreservesCalendar(): void
    {
        $pd = PlainDate::parse('2024-01-15[u-ca=hebrew]');

        static::assertSame(Calendar::Hebrew, $pd->calendar);
        static::assertSame(5784, $pd->year);
    }

    public function testPlainDateToStringIncludesNonIsoCalendar(): void
    {
        $pd = new PlainDate(2024, 1, 15, Calendar::Hebrew);

        static::assertStringContainsString('[u-ca=hebrew]', $pd->toString());
    }

    public function testPlainDateWithPreservesCalendar(): void
    {
        $pd = new PlainDate(2024, 1, 15, Calendar::Hebrew);

        // $pd->day is the Hebrew day. Use with() to change the calendar day.
        $pd2 = $pd->with(day: 1);

        static::assertSame(Calendar::Hebrew, $pd2->calendar);
    }

    public function testPlainDateAddPreservesCalendar(): void
    {
        $pd = new PlainDate(2024, 1, 15, Calendar::Hebrew);
        $pd2 = $pd->add(new Duration(days: 10));

        static::assertSame(Calendar::Hebrew, $pd2->calendar);
    }

    public function testPlainDateSubtractPreservesCalendar(): void
    {
        $pd = new PlainDate(2024, 1, 15, Calendar::Hebrew);
        $pd2 = $pd->subtract(new Duration(days: 10));

        static::assertSame(Calendar::Hebrew, $pd2->calendar);
    }

    public function testPlainDateToPlainDateTimePreservesCalendar(): void
    {
        $pd = new PlainDate(2024, 1, 15, Calendar::Hebrew);
        $pdt = $pd->toPlainDateTime();

        static::assertSame(Calendar::Hebrew, $pdt->calendar);
    }

    public function testPlainDateToPlainYearMonthPreservesCalendar(): void
    {
        $pd = new PlainDate(2024, 1, 15, Calendar::Hebrew);
        $pym = $pd->toPlainYearMonth();

        static::assertSame(Calendar::Hebrew, $pym->calendar);
    }

    public function testPlainDateToPlainMonthDayPreservesCalendar(): void
    {
        $pd = new PlainDate(2024, 1, 15, Calendar::Hebrew);
        $pmd = $pd->toPlainMonthDay();

        static::assertSame(Calendar::Hebrew, $pmd->calendar);
    }

    // =========================================================================
    // PlainDateTime
    // =========================================================================

    public function testPlainDateTimeConstructorAcceptsCalendar(): void
    {
        $pdt = new PlainDateTime(2024, 1, 15, 10, 30, 0, 0, 0, 0, Calendar::Japanese);

        static::assertSame(Calendar::Japanese, $pdt->calendar);
    }

    public function testPlainDateTimeConstructorDefaultsToIso(): void
    {
        $pdt = new PlainDateTime(2024, 1, 15);

        static::assertSame(Calendar::Iso8601, $pdt->calendar);
    }

    public function testPlainDateTimeYearMonthDayAreCalendarProjected(): void
    {
        $pdt = new PlainDateTime(2024, 1, 15, 10, 30, 0, 0, 0, 0, Calendar::Hebrew);

        static::assertSame(5784, $pdt->year);
    }

    public function testPlainDateTimeTimeFieldsDelegateCorrectly(): void
    {
        $pdt = new PlainDateTime(2024, 1, 15, 10, 30, 45, 100, 200, 300, Calendar::Hebrew);

        static::assertSame(10, $pdt->hour);
        static::assertSame(30, $pdt->minute);
        static::assertSame(45, $pdt->second);
        static::assertSame(100, $pdt->millisecond);
        static::assertSame(200, $pdt->microsecond);
        static::assertSame(300, $pdt->nanosecond);
    }

    public function testPlainDateTimeFromSpecPreservesCalendar(): void
    {
        $spec = new \Calendrics\Spec\PlainDateTime(2024, 1, 15, 10, 30, 0, 0, 0, 0, 'japanese');
        $pdt = PlainDateTime::fromSpec($spec);

        static::assertSame(Calendar::Japanese, $pdt->calendar);
        static::assertSame(2024, $pdt->toSpec()->isoYear);
        static::assertSame(1, $pdt->toSpec()->isoMonth);
        static::assertSame(15, $pdt->toSpec()->isoDay);
    }

    public function testPlainDateTimeFromSpecDoesNotCorruptNonIso(): void
    {
        $spec1 = new \Calendrics\Spec\PlainDateTime(2024, 1, 15, 12, 0, 0, 0, 0, 0, 'persian');
        $pdt = PlainDateTime::fromSpec($spec1);

        // Round-trip
        $spec2 = $pdt->toSpec();
        $pdt2 = PlainDateTime::fromSpec($spec2);

        static::assertSame($spec1->isoYear, $pdt2->toSpec()->isoYear);
        static::assertSame($spec1->isoMonth, $pdt2->toSpec()->isoMonth);
        static::assertSame($spec1->isoDay, $pdt2->toSpec()->isoDay);
        static::assertSame(Calendar::Persian, $pdt2->calendar);
    }

    public function testPlainDateTimeParsePreservesCalendar(): void
    {
        $pdt = PlainDateTime::parse('2024-01-15T10:30:00[u-ca=buddhist]');

        static::assertSame(Calendar::Buddhist, $pdt->calendar);
    }

    public function testPlainDateTimeAddPreservesCalendar(): void
    {
        $pdt = new PlainDateTime(2024, 1, 15, 10, 0, 0, 0, 0, 0, Calendar::Gregory);
        $pdt2 = $pdt->add(new Duration(days: 5));

        static::assertSame(Calendar::Gregory, $pdt2->calendar);
    }

    public function testPlainDateTimeToPlainDatePreservesCalendar(): void
    {
        $pdt = new PlainDateTime(2024, 1, 15, 10, 0, 0, 0, 0, 0, Calendar::Hebrew);
        $pd = $pdt->toPlainDate();

        static::assertSame(Calendar::Hebrew, $pd->calendar);
    }

    // =========================================================================
    // PlainYearMonth
    // =========================================================================

    public function testPlainYearMonthConstructorAcceptsCalendar(): void
    {
        $pym = new PlainYearMonth(2024, 6, Calendar::Buddhist);

        static::assertSame(Calendar::Buddhist, $pym->calendar);
    }

    public function testPlainYearMonthConstructorDefaultsToIso(): void
    {
        $pym = new PlainYearMonth(2024, 6);

        static::assertSame(Calendar::Iso8601, $pym->calendar);
    }

    public function testPlainYearMonthYearMonthAreCalendarProjected(): void
    {
        // Buddhist year = ISO year + 543
        $pym = new PlainYearMonth(2024, 6, Calendar::Buddhist);

        static::assertSame(2567, $pym->year);
    }

    public function testPlainYearMonthFromSpecPreservesCalendar(): void
    {
        $spec = new \Calendrics\Spec\PlainYearMonth(2024, 6, 'buddhist');
        $pym = PlainYearMonth::fromSpec($spec);

        static::assertSame(Calendar::Buddhist, $pym->calendar);
        static::assertSame(2024, $pym->toSpec()->isoYear);
        static::assertSame(6, $pym->toSpec()->isoMonth);
    }

    public function testPlainYearMonthFromSpecPreservesReferenceDay(): void
    {
        $spec = new \Calendrics\Spec\PlainYearMonth(2024, 3, 'iso8601', 15);
        $pym = PlainYearMonth::fromSpec($spec);

        static::assertSame(15, $pym->toSpec()->referenceISODay);
    }

    public function testPlainYearMonthFromSpecDoesNotCorruptNonIso(): void
    {
        $spec1 = new \Calendrics\Spec\PlainYearMonth(2024, 6, 'gregory');
        $pym = PlainYearMonth::fromSpec($spec1);
        $spec2 = $pym->toSpec();
        $pym2 = PlainYearMonth::fromSpec($spec2);

        static::assertSame($spec1->isoYear, $pym2->toSpec()->isoYear);
        static::assertSame($spec1->isoMonth, $pym2->toSpec()->isoMonth);
        static::assertSame(Calendar::Gregory, $pym2->calendar);
    }

    public function testPlainYearMonthParsePreservesCalendar(): void
    {
        $pym = PlainYearMonth::parse('2024-06-01[u-ca=japanese]');

        static::assertSame(Calendar::Japanese, $pym->calendar);
    }

    public function testPlainYearMonthAddPreservesCalendar(): void
    {
        $pym = new PlainYearMonth(2024, 6, Calendar::Buddhist);
        $pym2 = $pym->add(new Duration(months: 2));

        static::assertSame(Calendar::Buddhist, $pym2->calendar);
    }

    // =========================================================================
    // PlainMonthDay
    // =========================================================================

    public function testPlainMonthDayConstructorAcceptsCalendar(): void
    {
        $pmd = new PlainMonthDay(3, 15, Calendar::Coptic);

        static::assertSame(Calendar::Coptic, $pmd->calendar);
    }

    public function testPlainMonthDayConstructorDefaultsToIso(): void
    {
        $pmd = new PlainMonthDay(3, 15);

        static::assertSame(Calendar::Iso8601, $pmd->calendar);
    }

    public function testPlainMonthDayFromSpecPreservesCalendar(): void
    {
        $spec = new \Calendrics\Spec\PlainMonthDay(3, 15, 'coptic');
        $pmd = PlainMonthDay::fromSpec($spec);

        static::assertSame(Calendar::Coptic, $pmd->calendar);
        static::assertSame(3, $pmd->toSpec()->isoMonth);
        static::assertSame(15, $pmd->toSpec()->isoDay);
    }

    public function testPlainMonthDayFromSpecPreservesReferenceYear(): void
    {
        $spec = new \Calendrics\Spec\PlainMonthDay(6, 15, 'iso8601', 2000);
        $pmd = PlainMonthDay::fromSpec($spec);

        static::assertSame(2000, $pmd->toSpec()->referenceISOYear);
    }

    public function testPlainMonthDayFromSpecDoesNotCorruptNonIso(): void
    {
        $spec1 = new \Calendrics\Spec\PlainMonthDay(3, 15, 'indian');
        $pmd = PlainMonthDay::fromSpec($spec1);
        $spec2 = $pmd->toSpec();
        $pmd2 = PlainMonthDay::fromSpec($spec2);

        static::assertSame($spec1->isoMonth, $pmd2->toSpec()->isoMonth);
        static::assertSame($spec1->isoDay, $pmd2->toSpec()->isoDay);
        static::assertSame(Calendar::Indian, $pmd2->calendar);
    }

    public function testPlainMonthDayParsePreservesCalendar(): void
    {
        $pmd = PlainMonthDay::parse('1972-03-15[u-ca=chinese]');

        static::assertSame(Calendar::Chinese, $pmd->calendar);
    }

    // =========================================================================
    // ZonedDateTime
    // =========================================================================

    public function testZonedDateTimeConstructorAcceptsCalendar(): void
    {
        $zdt = new ZonedDateTime(0, 'UTC', Calendar::Hebrew);

        static::assertSame(Calendar::Hebrew, $zdt->calendar);
    }

    public function testZonedDateTimeConstructorDefaultsToIso(): void
    {
        $zdt = new ZonedDateTime(0, 'UTC');

        static::assertSame(Calendar::Iso8601, $zdt->calendar);
    }

    public function testZonedDateTimeFromSpecPreservesCalendar(): void
    {
        $spec = new \Calendrics\Spec\ZonedDateTime(0, 'UTC', 'gregory');
        $zdt = ZonedDateTime::fromSpec($spec);

        static::assertSame(Calendar::Gregory, $zdt->calendar);
    }

    public function testZonedDateTimeFromSpecDoesNotCorruptCalendar(): void
    {
        $spec1 = new \Calendrics\Spec\ZonedDateTime(0, 'UTC', 'japanese');
        $zdt = ZonedDateTime::fromSpec($spec1);
        $spec2 = $zdt->toSpec();
        $zdt2 = ZonedDateTime::fromSpec($spec2);

        static::assertSame($spec1->epochNanoseconds, $zdt2->epochNanoseconds);
        static::assertSame(Calendar::Japanese, $zdt2->calendar);
    }

    public function testZonedDateTimeParsePreservesCalendar(): void
    {
        $zdt = ZonedDateTime::parse('2024-01-15T10:30:00+00:00[UTC][u-ca=hebrew]');

        static::assertSame(Calendar::Hebrew, $zdt->calendar);
    }

    public function testZonedDateTimeAddPreservesCalendar(): void
    {
        $zdt = new ZonedDateTime(0, 'UTC', Calendar::Buddhist);
        $zdt2 = $zdt->add(new Duration(days: 5));

        static::assertSame(Calendar::Buddhist, $zdt2->calendar);
    }

    public function testZonedDateTimeToPlainDatePreservesCalendar(): void
    {
        $zdt = new ZonedDateTime(0, 'UTC', Calendar::Hebrew);
        $pd = $zdt->toPlainDate();

        static::assertSame(Calendar::Hebrew, $pd->calendar);
    }

    public function testZonedDateTimeToPlainDateTimePreservesCalendar(): void
    {
        $zdt = new ZonedDateTime(0, 'UTC', Calendar::Japanese);
        $pdt = $zdt->toPlainDateTime();

        static::assertSame(Calendar::Japanese, $pdt->calendar);
    }

    // =========================================================================
    // Duration relativeTo with non-ISO calendar
    // =========================================================================

    public function testDurationCompareWithNonIsoRelativeTo(): void
    {
        $d1 = new Duration(months: 1);
        $d2 = new Duration(days: 30);
        $rt = new PlainDate(2024, 1, 15, Calendar::Hebrew);

        // Should not throw; should compare correctly. The actual ordering depends
        // on the Hebrew calendar month length at the reference date; we only
        // require a valid comparison result (-1, 0, or 1).
        $result = Duration::compare($d1, $d2, $rt);

        static::assertContains($result, [-1, 0, 1]);
    }

    public function testDurationTotalWithNonIsoRelativeTo(): void
    {
        $d = new Duration(years: 1);
        $rt = new PlainDate(2024, 1, 15, Calendar::Hebrew);

        // 2024 is a leap year, so 1 year from Jan 15 should be 366 days
        $result = $d->total(Unit::Day, $rt);

        static::assertSame(366.0, (float) $result);
    }

    public function testDurationRoundWithNonIsoRelativeTo(): void
    {
        $d = new Duration(months: 15);
        $rt = new PlainDate(2024, 1, 15, Calendar::Buddhist);

        $rounded = $d->round(smallestUnit: Unit::Year, relativeTo: $rt);

        static::assertSame(1, $rounded->years);
    }

    // =========================================================================
    // ISO calendar backward compatibility
    // =========================================================================

    public function testPlainDateIsoBackwardCompatibility(): void
    {
        $pd = new PlainDate(2024, 6, 15);

        // Same values for ISO calendar
        static::assertSame(2024, $pd->year);
        static::assertSame(6, $pd->month);
        static::assertSame(15, $pd->day);
        static::assertSame(Calendar::Iso8601, $pd->calendar);
    }

    public function testPlainDateTimeIsoBackwardCompatibility(): void
    {
        $pdt = new PlainDateTime(2024, 6, 15, 10, 30, 45, 100, 200, 300);

        static::assertSame(2024, $pdt->year);
        static::assertSame(6, $pdt->month);
        static::assertSame(15, $pdt->day);
        static::assertSame(10, $pdt->hour);
        static::assertSame(30, $pdt->minute);
        static::assertSame(45, $pdt->second);
        static::assertSame(100, $pdt->millisecond);
        static::assertSame(200, $pdt->microsecond);
        static::assertSame(300, $pdt->nanosecond);
        static::assertSame(Calendar::Iso8601, $pdt->calendar);
    }

    public function testPlainYearMonthIsoBackwardCompatibility(): void
    {
        $pym = new PlainYearMonth(2024, 6);

        static::assertSame(2024, $pym->year);
        static::assertSame(6, $pym->month);
        static::assertSame(Calendar::Iso8601, $pym->calendar);
    }

    public function testPlainMonthDayIsoBackwardCompatibility(): void
    {
        $pmd = new PlainMonthDay(3, 15);

        static::assertSame(3, $pmd->month);
        static::assertSame(15, $pmd->day);
        static::assertSame(Calendar::Iso8601, $pmd->calendar);
    }

    public function testZonedDateTimeIsoBackwardCompatibility(): void
    {
        $zdt = new ZonedDateTime(0, 'UTC');

        static::assertSame(Calendar::Iso8601, $zdt->calendar);
        static::assertSame(0, $zdt->epochNanoseconds);
    }

    // =========================================================================
    // Cross-type conversions preserve calendar
    // =========================================================================

    public function testPlainDateToZonedDateTimePreservesCalendar(): void
    {
        $pd = new PlainDate(2024, 1, 15, Calendar::Buddhist);
        $zdt = $pd->toZonedDateTime('UTC');

        static::assertSame(Calendar::Buddhist, $zdt->calendar);
    }

    // =========================================================================
    // ext-intl composer.json requirement
    // =========================================================================

    public function testIntlExtensionIsLoaded(): void
    {
        static::assertTrue(extension_loaded('intl'), 'ext-intl must be loaded');
    }
}
