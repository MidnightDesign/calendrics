<?php

declare(strict_types=1);

namespace Calendrics\Tests\Porcelain;

use Calendrics\Calendar;
use Calendrics\CalendarDisplay;
use Calendrics\Exception\RangeError;
use Calendrics\Overflow;
use Calendrics\PlainMonthDay;

final class PlainMonthDayTest extends CalendricsTestCase
{
    // -------------------------------------------------------------------------
    // Constructor & readonly properties
    // -------------------------------------------------------------------------

    public function testConstructorSetsFields(): void
    {
        $md = new PlainMonthDay(12, 25);

        static::assertSame(12, $md->month);
        static::assertSame(25, $md->day);
    }

    public function testConstructorRejectsInvalidDay(): void
    {
        $this->expectException(RangeError::class);
        new PlainMonthDay(2, 30);
    }

    public function testConstructorAcceptsFeb29(): void
    {
        $md = new PlainMonthDay(2, 29);

        static::assertSame(2, $md->month);
        static::assertSame(29, $md->day);
    }

    // -------------------------------------------------------------------------
    // Virtual properties
    // -------------------------------------------------------------------------

    public function testCalendarIsIso8601(): void
    {
        static::assertSame(Calendar::Iso8601, new PlainMonthDay(12, 25)->calendar);
    }

    public function testMonthCode(): void
    {
        static::assertSame('M01', new PlainMonthDay(1, 1)->monthCode);
        static::assertSame('M06', new PlainMonthDay(6, 15)->monthCode);
        static::assertSame('M12', new PlainMonthDay(12, 31)->monthCode);
    }

    // -------------------------------------------------------------------------
    // parse
    // -------------------------------------------------------------------------

    public function testParseWithDoubleDashPrefix(): void
    {
        $md = PlainMonthDay::parse('--12-25');

        static::assertSame(12, $md->month);
        static::assertSame(25, $md->day);
    }

    public function testParseFullDate(): void
    {
        // Parsing a full date string should still extract the month-day
        $md = PlainMonthDay::parse('2020-06-15');

        static::assertSame(6, $md->month);
        static::assertSame(15, $md->day);
    }

    public function testParseInvalidStringThrows(): void
    {
        $this->expectException(RangeError::class);
        PlainMonthDay::parse('not-a-date');
    }

    public function testParseFeb29(): void
    {
        $md = PlainMonthDay::parse('--02-29');

        static::assertSame(2, $md->month);
        static::assertSame(29, $md->day);
    }

    // -------------------------------------------------------------------------
    // with
    // -------------------------------------------------------------------------

    public function testWithMonth(): void
    {
        $md = new PlainMonthDay(6, 15);
        $result = $md->with(month: 3);

        static::assertSame(3, $result->month);
        static::assertSame(15, $result->day);
    }

    public function testWithDay(): void
    {
        $md = new PlainMonthDay(6, 15);
        $result = $md->with(day: 1);

        static::assertSame(6, $result->month);
        static::assertSame(1, $result->day);
    }

    public function testWithConstrainsDay(): void
    {
        // Day 31 constrained to max for Feb (29)
        $md = new PlainMonthDay(1, 31);
        $result = $md->with(month: 2);

        static::assertSame(29, $result->day);
    }

    public function testWithRejectOverflow(): void
    {
        $md = new PlainMonthDay(1, 31);

        $this->expectException(RangeError::class);
        $md->with(month: 2, overflow: Overflow::Reject);
    }

    public function testWithReturnsNewInstance(): void
    {
        $md = new PlainMonthDay(6, 15);
        $result = $md->with(month: 3);

        static::assertNotSame($md, $result);
        static::assertSame(6, $md->month);
    }

    // -------------------------------------------------------------------------
    // equals
    // -------------------------------------------------------------------------

    public function testEqualsTrue(): void
    {
        $a = new PlainMonthDay(12, 25);
        $b = new PlainMonthDay(12, 25);

        static::assertTrue($a->equals($b));
    }

    public function testEqualsFalseDifferentMonth(): void
    {
        $a = new PlainMonthDay(12, 25);
        $b = new PlainMonthDay(11, 25);

        static::assertFalse($a->equals($b));
    }

    public function testEqualsFalseDifferentDay(): void
    {
        $a = new PlainMonthDay(12, 25);
        $b = new PlainMonthDay(12, 26);

        static::assertFalse($a->equals($b));
    }

    // -------------------------------------------------------------------------
    // toString
    // -------------------------------------------------------------------------

    public function testToStringDefault(): void
    {
        $md = new PlainMonthDay(12, 25);

        static::assertSame('12-25', $md->toString());
    }

    public function testToStringCalendarAlways(): void
    {
        $md = new PlainMonthDay(12, 25);

        static::assertSame('1972-12-25[u-ca=iso8601]', $md->toString(CalendarDisplay::Always));
    }

    public function testToStringCalendarNever(): void
    {
        $md = new PlainMonthDay(12, 25);

        static::assertSame('12-25', $md->toString(CalendarDisplay::Never));
    }

    public function testToStringCalendarCritical(): void
    {
        $md = new PlainMonthDay(12, 25);

        static::assertSame('1972-12-25[!u-ca=iso8601]', $md->toString(CalendarDisplay::Critical));
    }

    public function testToStringSingleDigitMonth(): void
    {
        $md = new PlainMonthDay(1, 5);

        static::assertSame('01-05', $md->toString());
    }

    // -------------------------------------------------------------------------
    // toPlainDate
    // -------------------------------------------------------------------------

    public function testToPlainDate(): void
    {
        $md = new PlainMonthDay(12, 25);
        $date = $md->toPlainDate(2020);

        static::assertSame(2020, $date->year);
        static::assertSame(12, $date->month);
        static::assertSame(25, $date->day);
    }

    public function testToPlainDateFeb29LeapYear(): void
    {
        $md = new PlainMonthDay(2, 29);
        $date = $md->toPlainDate(2020);

        static::assertSame(2020, $date->year);
        static::assertSame(2, $date->month);
        static::assertSame(29, $date->day);
    }

    public function testToPlainDateFeb29NonLeapYear(): void
    {
        // Feb 29 combined with a non-leap year should constrain day to 28
        $md = new PlainMonthDay(2, 29);
        $date = $md->toPlainDate(2019);

        static::assertSame(2019, $date->year);
        static::assertSame(2, $date->month);
        static::assertSame(28, $date->day);
    }

    public function testToPlainDateDifferentYears(): void
    {
        $md = new PlainMonthDay(6, 15);

        $date2020 = $md->toPlainDate(2020);
        $date2025 = $md->toPlainDate(2025);

        static::assertSame(2020, $date2020->year);
        static::assertSame(2025, $date2025->year);
        static::assertSame(6, $date2020->month);
        static::assertSame(15, $date2020->day);
    }

    // -------------------------------------------------------------------------
    // __toString / jsonSerialize
    // -------------------------------------------------------------------------

    public function testMagicToString(): void
    {
        $md = new PlainMonthDay(12, 25);

        static::assertSame('12-25', (string) $md);
    }

    public function testJsonSerialize(): void
    {
        $md = new PlainMonthDay(12, 25);

        static::assertSame('"12-25"', json_encode($md));
    }

    // -------------------------------------------------------------------------
    // toSpec / fromSpec
    // -------------------------------------------------------------------------

    public function testToSpecReturnsSpecInstance(): void
    {
        $md = new PlainMonthDay(12, 25);
        $spec = $md->toSpec();

        static::assertSame(12, $spec->isoMonth);
        static::assertSame(25, $spec->day);
    }

    public function testFromSpecCreatesInstance(): void
    {
        $spec = new \Calendrics\Spec\PlainMonthDay(12, 25);
        $md = PlainMonthDay::fromSpec($spec);

        static::assertSame(12, $md->month);
        static::assertSame(25, $md->day);
    }

    public function testToSpecRoundTrip(): void
    {
        $md = new PlainMonthDay(12, 25);
        $restored = PlainMonthDay::fromSpec($md->toSpec());

        static::assertTrue($md->equals($restored));
    }

    // -------------------------------------------------------------------------
    // __debugInfo
    // -------------------------------------------------------------------------

    public function testDebugInfo(): void
    {
        $md = new PlainMonthDay(12, 25);
        $info = $md->__debugInfo();

        static::assertSame(12, $info['month']);
        static::assertSame(25, $info['day']);
        static::assertSame(Calendar::Iso8601, $info['calendar']);
        static::assertSame('12-25', $info['iso']);
    }

    // -------------------------------------------------------------------------
    // Constructor with Calendar enum
    // -------------------------------------------------------------------------

    public function testConstructorWithCalendarEnum(): void
    {
        $md = new PlainMonthDay(12, 25, Calendar::Iso8601);

        static::assertSame(12, $md->month);
        static::assertSame(25, $md->day);
        static::assertSame(Calendar::Iso8601, $md->calendar);
    }

    // -------------------------------------------------------------------------
    // fromFields()
    // -------------------------------------------------------------------------

    public function testFromPropertyBag(): void
    {
        $md = PlainMonthDay::fromFields(monthCode: 'M12', day: 25);

        static::assertSame(12, $md->month);
        static::assertSame(25, $md->day);
    }

    public function testFromFieldsForwardsCalendar(): void
    {
        $md = PlainMonthDay::fromFields(monthCode: 'M06', day: 15, calendar: Calendar::Gregory);

        static::assertSame(Calendar::Gregory, $md->calendar);
    }

    public function testFromFieldsForwardsEraAndEraYear(): void
    {
        $md = PlainMonthDay::fromFields(
            monthCode: 'M06',
            day: 15,
            calendar: Calendar::Gregory,
            year: 2020,
            era: 'ce',
            eraYear: 2020,
        );

        static::assertSame(6, $md->month);
        static::assertSame(15, $md->day);
        static::assertSame(Calendar::Gregory, $md->calendar);
    }

    public function testFromFieldsForwardsOverflowReject(): void
    {
        $this->expectException(RangeError::class);

        PlainMonthDay::fromFields(month: 2, day: 30, overflow: Overflow::Reject);
    }

    public function testFromFieldsForwardsYear(): void
    {
        // Hebrew monthCode "M05L" is valid only in leap years. Year 5783 is not
        // a leap year, so passing it forces rejection; without `year`, the spec
        // falls back to a reference leap year and would accept.
        $this->expectException(RangeError::class);

        PlainMonthDay::fromFields(monthCode: 'M05L', day: 1, calendar: Calendar::Hebrew, year: 5783);
    }

    public function testFromFieldsRejectsNonPositiveMonthOnNonIsoCalendar(): void
    {
        // Spec: PrepareCalendarFields runs ToPositiveIntegerWithTruncation on
        // `month` regardless of calendar, so a non-ISO calendar must reject 0
        // or negative months. Upstream test262 only covers the ISO path.
        // The 0 literal violates the int<1, 12> PHPDoc on the porcelain
        // signature; we suppress the analyzer warnings to verify the runtime
        // safety net still fires.
        $this->expectException(RangeError::class);

        /** @psalm-suppress InvalidArgument */
        // @mago-ignore analysis:invalid-argument
        PlainMonthDay::fromFields(month: 0, day: 1, calendar: Calendar::Gregory, year: 2024); // @phpstan-ignore argument.type
    }

    public function testFromFieldsRejectsNonPositiveDayOnNonIsoCalendar(): void
    {
        // Spec: same positivity rule for `day`. Non-ISO companion to the ISO
        // negative-month-or-day fixture, which upstream test262 lacks.
        $this->expectException(RangeError::class);

        /** @psalm-suppress InvalidArgument */
        // @mago-ignore analysis:invalid-argument
        PlainMonthDay::fromFields(month: 1, day: 0, calendar: Calendar::Gregory, year: 2024); // @phpstan-ignore argument.type
    }

    public function testFromFieldsRejectsNonPositiveDayOnNonIsoCalendarWithMonthCodeNoYear(): void
    {
        // Spec: ToPositiveIntegerWithTruncation rejects day ≤ 0 in
        // PrepareCalendarFields, regardless of which other fields are present.
        // Distinct code path from the year-provided variant above.
        $this->expectException(RangeError::class);

        /** @psalm-suppress InvalidArgument */
        // @mago-ignore analysis:invalid-argument
        PlainMonthDay::fromFields(monthCode: 'M01', day: 0, calendar: Calendar::Gregory); // @phpstan-ignore argument.type
    }

    public function testFromFieldsRequiresYearOnEralessCalendarEvenWithEraAndEraYear(): void
    {
        // Spec: CalendarExtraFields returns era/era-year only when
        // CalendarSupportsEra is true. For 'chinese' and 'dangi', era and
        // eraYear are silently ignored; with year/monthCode also missing,
        // NonISOResolveFields throws TypeError because Year is unset and the
        // calendar has no era support to fall back on.
        $this->expectException(\TypeError::class);

        PlainMonthDay::fromFields(month: 5, day: 1, calendar: Calendar::Chinese, era: 'x', eraYear: 1);
    }

    // -------------------------------------------------------------------------
    // with() expanded fields
    // -------------------------------------------------------------------------

    public function testWithMonthCode(): void
    {
        $md = new PlainMonthDay(6, 15);
        $result = $md->with(monthCode: 'M03');

        static::assertSame(3, $result->month);
        static::assertSame(15, $result->day);
    }

    // -------------------------------------------------------------------------
    // fromSpec round-trip with Calendar enum
    // -------------------------------------------------------------------------

    public function testFromSpecPreservesCalendar(): void
    {
        $spec = new \Calendrics\Spec\PlainMonthDay(12, 25);
        $md = PlainMonthDay::fromSpec($spec);

        static::assertSame(Calendar::Iso8601, $md->calendar);
    }
}
