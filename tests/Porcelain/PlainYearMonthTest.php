<?php

declare(strict_types=1);

namespace Calendrics\Tests\Porcelain;

use Calendrics\Calendar;
use Calendrics\CalendarDisplay;
use Calendrics\Duration;
use Calendrics\Exception\RangeError;
use Calendrics\PlainYearMonth;
use Calendrics\RoundingMode;
use Calendrics\Unit;

final class PlainYearMonthTest extends CalendricsTestCase
{
    // -------------------------------------------------------------------------
    // Constructor & readonly properties
    // -------------------------------------------------------------------------

    public function testConstructorSetsFields(): void
    {
        $ym = new PlainYearMonth(2020, 6);

        static::assertSame(2020, $ym->year);
        static::assertSame(6, $ym->month);
    }

    // -------------------------------------------------------------------------
    // Virtual properties
    // -------------------------------------------------------------------------

    public function testCalendarIsIso8601(): void
    {
        static::assertSame(Calendar::Iso8601, new PlainYearMonth(2020, 6)->calendar);
    }

    public function testEraIsNullForIso8601(): void
    {
        $ym = new PlainYearMonth(2020, 6);

        static::assertNull($ym->era);
        static::assertNull($ym->eraYear);
    }

    public function testMonthCode(): void
    {
        static::assertSame('M01', new PlainYearMonth(2020, 1)->monthCode);
        static::assertSame('M06', new PlainYearMonth(2020, 6)->monthCode);
        static::assertSame('M12', new PlainYearMonth(2020, 12)->monthCode);
    }

    public function testDaysInMonth(): void
    {
        static::assertSame(31, new PlainYearMonth(2020, 1)->daysInMonth);
        static::assertSame(29, new PlainYearMonth(2020, 2)->daysInMonth);
        static::assertSame(28, new PlainYearMonth(2019, 2)->daysInMonth);
        static::assertSame(30, new PlainYearMonth(2020, 4)->daysInMonth);
    }

    public function testDaysInYear(): void
    {
        static::assertSame(366, new PlainYearMonth(2020, 1)->daysInYear);
        static::assertSame(365, new PlainYearMonth(2019, 1)->daysInYear);
    }

    public function testMonthsInYear(): void
    {
        static::assertSame(12, new PlainYearMonth(2020, 1)->monthsInYear);
    }

    public function testInLeapYear(): void
    {
        static::assertTrue(new PlainYearMonth(2020, 1)->inLeapYear);
        static::assertFalse(new PlainYearMonth(2019, 1)->inLeapYear);
    }

    // -------------------------------------------------------------------------
    // parse
    // -------------------------------------------------------------------------

    public function testParseBasic(): void
    {
        $ym = PlainYearMonth::parse('2020-06');

        static::assertSame(2020, $ym->year);
        static::assertSame(6, $ym->month);
    }

    public function testParseWithDay(): void
    {
        // Parsing a full date string should still yield the year-month
        $ym = PlainYearMonth::parse('2020-06-15');

        static::assertSame(2020, $ym->year);
        static::assertSame(6, $ym->month);
    }

    public function testParseInvalidStringThrows(): void
    {
        $this->expectException(RangeError::class);
        PlainYearMonth::parse('not-a-date');
    }

    public function testParseNegativeYear(): void
    {
        $ym = PlainYearMonth::parse('-001000-01');

        static::assertSame(-1000, $ym->year);
        static::assertSame(1, $ym->month);
    }

    // -------------------------------------------------------------------------
    // compare
    // -------------------------------------------------------------------------

    public function testCompareSame(): void
    {
        $a = new PlainYearMonth(2020, 6);
        $b = new PlainYearMonth(2020, 6);

        static::assertSame(0, PlainYearMonth::compare($a, $b));
    }

    public function testCompareEarlierVsLater(): void
    {
        $a = new PlainYearMonth(2020, 1);
        $b = new PlainYearMonth(2020, 12);

        static::assertLessThan(0, PlainYearMonth::compare($a, $b));
        static::assertGreaterThan(0, PlainYearMonth::compare($b, $a));
    }

    public function testCompareByYear(): void
    {
        $a = new PlainYearMonth(2019, 12);
        $b = new PlainYearMonth(2020, 1);

        static::assertLessThan(0, PlainYearMonth::compare($a, $b));
    }

    // -------------------------------------------------------------------------
    // with
    // -------------------------------------------------------------------------

    public function testWithYear(): void
    {
        $ym = new PlainYearMonth(2020, 6);
        $result = $ym->with(year: 2021);

        static::assertSame(2021, $result->year);
        static::assertSame(6, $result->month);
    }

    public function testWithMonth(): void
    {
        $ym = new PlainYearMonth(2020, 6);
        $result = $ym->with(month: 1);

        static::assertSame(2020, $result->year);
        static::assertSame(1, $result->month);
    }

    public function testWithReturnsNewInstance(): void
    {
        $ym = new PlainYearMonth(2020, 6);
        $result = $ym->with(year: 2021);

        static::assertNotSame($ym, $result);
        static::assertSame(2020, $ym->year);
    }

    // -------------------------------------------------------------------------
    // add / subtract
    // -------------------------------------------------------------------------

    public function testAddMonths(): void
    {
        $ym = new PlainYearMonth(2020, 10);
        $result = $ym->add(new Duration(months: 5));

        static::assertSame(2021, $result->year);
        static::assertSame(3, $result->month);
    }

    public function testAddYears(): void
    {
        $ym = new PlainYearMonth(2020, 6);
        $result = $ym->add(new Duration(years: 2));

        static::assertSame(2022, $result->year);
        static::assertSame(6, $result->month);
    }

    public function testAddYearsAndMonths(): void
    {
        $ym = new PlainYearMonth(2020, 6);
        $result = $ym->add(new Duration(years: 1, months: 8));

        static::assertSame(2022, $result->year);
        static::assertSame(2, $result->month);
    }

    public function testSubtractMonths(): void
    {
        $ym = new PlainYearMonth(2020, 3);
        $result = $ym->subtract(new Duration(months: 5));

        static::assertSame(2019, $result->year);
        static::assertSame(10, $result->month);
    }

    public function testSubtractYears(): void
    {
        $ym = new PlainYearMonth(2020, 6);
        $result = $ym->subtract(new Duration(years: 3));

        static::assertSame(2017, $result->year);
        static::assertSame(6, $result->month);
    }

    public function testAddDoesNotMutateOriginal(): void
    {
        $ym = new PlainYearMonth(2020, 6);
        $ym->add(new Duration(months: 1));

        static::assertSame(6, $ym->month);
    }

    // -------------------------------------------------------------------------
    // since / until
    // -------------------------------------------------------------------------

    public function testSinceMonths(): void
    {
        $a = new PlainYearMonth(2020, 1);
        $b = new PlainYearMonth(2020, 4);

        $dur = $b->since($a);

        static::assertSame(0, $dur->years);
        static::assertSame(3, $dur->months);
    }

    public function testUntilMonths(): void
    {
        $a = new PlainYearMonth(2020, 1);
        $b = new PlainYearMonth(2020, 4);

        $dur = $a->until($b);

        static::assertSame(0, $dur->years);
        static::assertSame(3, $dur->months);
    }

    public function testSinceWithLargestUnitYear(): void
    {
        $a = new PlainYearMonth(2018, 6);
        $b = new PlainYearMonth(2020, 9);

        $dur = $b->since($a, largestUnit: Unit::Year);

        static::assertSame(2, $dur->years);
        static::assertSame(3, $dur->months);
    }

    public function testUntilWithLargestUnitYear(): void
    {
        $a = new PlainYearMonth(2018, 6);
        $b = new PlainYearMonth(2020, 9);

        $dur = $a->until($b, largestUnit: Unit::Year);

        static::assertSame(2, $dur->years);
        static::assertSame(3, $dur->months);
    }

    public function testSinceNegative(): void
    {
        $a = new PlainYearMonth(2020, 6);
        $b = new PlainYearMonth(2020, 1);

        $dur = $b->since($a);

        static::assertSame(-5, $dur->months);
    }

    public function testUntilNegative(): void
    {
        $a = new PlainYearMonth(2020, 6);
        $b = new PlainYearMonth(2020, 1);

        $dur = $a->until($b);

        static::assertSame(-5, $dur->months);
    }

    public function testSinceWithSmallestUnitYear(): void
    {
        $a = new PlainYearMonth(2018, 6);
        $b = new PlainYearMonth(2020, 9);

        $dur = $b->since($a, smallestUnit: Unit::Year);

        static::assertSame(2, $dur->years);
        static::assertSame(0, $dur->months);
    }

    // -------------------------------------------------------------------------
    // equals
    // -------------------------------------------------------------------------

    public function testEqualsTrue(): void
    {
        $a = new PlainYearMonth(2020, 6);
        $b = new PlainYearMonth(2020, 6);

        static::assertTrue($a->equals($b));
    }

    public function testEqualsFalse(): void
    {
        $a = new PlainYearMonth(2020, 6);
        $b = new PlainYearMonth(2020, 7);

        static::assertFalse($a->equals($b));
    }

    public function testEqualsDifferentYear(): void
    {
        $a = new PlainYearMonth(2020, 6);
        $b = new PlainYearMonth(2021, 6);

        static::assertFalse($a->equals($b));
    }

    // -------------------------------------------------------------------------
    // toString
    // -------------------------------------------------------------------------

    public function testToStringDefault(): void
    {
        $ym = new PlainYearMonth(2020, 6);

        static::assertSame('2020-06', $ym->toString());
    }

    public function testToStringCalendarAlways(): void
    {
        $ym = new PlainYearMonth(2020, 6);

        static::assertSame('2020-06-01[u-ca=iso8601]', $ym->toString(CalendarDisplay::Always));
    }

    public function testToStringCalendarNever(): void
    {
        $ym = new PlainYearMonth(2020, 6);

        static::assertSame('2020-06', $ym->toString(CalendarDisplay::Never));
    }

    public function testToStringCalendarCritical(): void
    {
        $ym = new PlainYearMonth(2020, 6);

        static::assertSame('2020-06-01[!u-ca=iso8601]', $ym->toString(CalendarDisplay::Critical));
    }

    public function testToStringNegativeYear(): void
    {
        $ym = new PlainYearMonth(-1000, 1);

        static::assertSame('-001000-01', $ym->toString());
    }

    // -------------------------------------------------------------------------
    // toPlainDate
    // -------------------------------------------------------------------------

    public function testToPlainDate(): void
    {
        $ym = new PlainYearMonth(2020, 6);
        $date = $ym->toPlainDate(15);

        static::assertSame(2020, $date->year);
        static::assertSame(6, $date->month);
        static::assertSame(15, $date->day);
    }

    public function testToPlainDateFirstDay(): void
    {
        $ym = new PlainYearMonth(2020, 2);
        $date = $ym->toPlainDate(1);

        static::assertSame(2020, $date->year);
        static::assertSame(2, $date->month);
        static::assertSame(1, $date->day);
    }

    public function testToPlainDateLastDayLeapYear(): void
    {
        $ym = new PlainYearMonth(2020, 2);
        $date = $ym->toPlainDate(29);

        static::assertSame(29, $date->day);
    }

    // -------------------------------------------------------------------------
    // __toString / jsonSerialize
    // -------------------------------------------------------------------------

    public function testMagicToString(): void
    {
        $ym = new PlainYearMonth(2020, 6);

        static::assertSame('2020-06', (string) $ym);
    }

    public function testJsonSerialize(): void
    {
        $ym = new PlainYearMonth(2020, 6);

        static::assertSame('"2020-06"', json_encode($ym));
    }

    // -------------------------------------------------------------------------
    // toSpec / fromSpec
    // -------------------------------------------------------------------------

    public function testToSpecReturnsSpecInstance(): void
    {
        $ym = new PlainYearMonth(2020, 6);
        $spec = $ym->toSpec();

        static::assertSame(2020, $spec->year);
        static::assertSame(6, $spec->month);
    }

    public function testFromSpecCreatesInstance(): void
    {
        $spec = new \Calendrics\Spec\PlainYearMonth(2020, 6);
        $ym = PlainYearMonth::fromSpec($spec);

        static::assertSame(2020, $ym->year);
        static::assertSame(6, $ym->month);
    }

    public function testToSpecRoundTrip(): void
    {
        $ym = new PlainYearMonth(2020, 6);
        $restored = PlainYearMonth::fromSpec($ym->toSpec());

        static::assertTrue($ym->equals($restored));
    }

    // -------------------------------------------------------------------------
    // __debugInfo
    // -------------------------------------------------------------------------

    public function testDebugInfo(): void
    {
        $ym = new PlainYearMonth(2020, 6);
        $info = $ym->__debugInfo();

        static::assertSame(2020, $info['year']);
        static::assertSame(6, $info['month']);
        static::assertSame(Calendar::Iso8601, $info['calendar']);
        static::assertSame('2020-06', $info['iso']);
    }

    // -------------------------------------------------------------------------
    // Mutation coverage: since() forwards all options
    // -------------------------------------------------------------------------

    public function testSinceForwardsLargestUnit(): void
    {
        $a = new PlainYearMonth(2018, 6);
        $b = new PlainYearMonth(2020, 9);
        $dur = $b->since($a, largestUnit: Unit::Month);

        static::assertSame(0, $dur->years);
        static::assertSame(27, $dur->months);
    }

    public function testSinceForwardsRoundingMode(): void
    {
        $a = new PlainYearMonth(2020, 1);
        $b = new PlainYearMonth(2020, 8);

        $trunc = $b->since($a, smallestUnit: Unit::Month, roundingIncrement: 3, roundingMode: RoundingMode::Trunc);
        $ceil = $b->since($a, smallestUnit: Unit::Month, roundingIncrement: 3, roundingMode: RoundingMode::Ceil);

        // 7 months truncated to nearest 3 = 6; ceiled to nearest 3 = 9
        static::assertSame(6, $trunc->months);
        static::assertSame(9, $ceil->months);
    }

    // -------------------------------------------------------------------------
    // Mutation coverage: until() forwards all options
    // -------------------------------------------------------------------------

    public function testUntilForwardsLargestUnit(): void
    {
        $a = new PlainYearMonth(2018, 6);
        $b = new PlainYearMonth(2020, 9);
        $dur = $a->until($b, largestUnit: Unit::Month);

        static::assertSame(0, $dur->years);
        static::assertSame(27, $dur->months);
    }

    public function testUntilForwardsSmallestUnit(): void
    {
        $a = new PlainYearMonth(2018, 6);
        $b = new PlainYearMonth(2020, 9);
        $dur = $a->until($b, smallestUnit: Unit::Year);

        static::assertSame(2, $dur->years);
        static::assertSame(0, $dur->months);
    }

    public function testUntilForwardsRoundingMode(): void
    {
        $a = new PlainYearMonth(2020, 1);
        $b = new PlainYearMonth(2020, 8);

        $trunc = $a->until($b, smallestUnit: Unit::Month, roundingIncrement: 3, roundingMode: RoundingMode::Trunc);
        $ceil = $a->until($b, smallestUnit: Unit::Month, roundingIncrement: 3, roundingMode: RoundingMode::Ceil);

        static::assertSame(6, $trunc->months);
        static::assertSame(9, $ceil->months);
    }

    // -------------------------------------------------------------------------
    // Constructor with Calendar enum
    // -------------------------------------------------------------------------

    public function testConstructorWithCalendarEnum(): void
    {
        $ym = new PlainYearMonth(2020, 6, Calendar::Iso8601);

        static::assertSame(2020, $ym->year);
        static::assertSame(6, $ym->month);
        static::assertSame(Calendar::Iso8601, $ym->calendar);
    }

    // -------------------------------------------------------------------------
    // fromFields()
    // -------------------------------------------------------------------------

    public function testFromPropertyBag(): void
    {
        $ym = PlainYearMonth::fromFields(year: 2020, monthCode: 'M06');

        static::assertSame(2020, $ym->year);
        static::assertSame(6, $ym->month);
    }

    public function testFromFieldsForwardsCalendar(): void
    {
        $ym = PlainYearMonth::fromFields(year: 2024, month: 6, calendar: Calendar::Gregory);

        static::assertSame(Calendar::Gregory, $ym->calendar);
    }

    // -------------------------------------------------------------------------
    // with() expanded fields
    // -------------------------------------------------------------------------

    public function testWithMonthCode(): void
    {
        $ym = new PlainYearMonth(2020, 6);
        $result = $ym->with(monthCode: 'M03');

        static::assertSame(3, $result->month);
        static::assertSame(2020, $result->year);
    }

    // -------------------------------------------------------------------------
    // fromSpec round-trip with Calendar enum
    // -------------------------------------------------------------------------

    public function testFromSpecPreservesCalendar(): void
    {
        $spec = new \Calendrics\Spec\PlainYearMonth(2020, 6);
        $ym = PlainYearMonth::fromSpec($spec);

        static::assertSame(Calendar::Iso8601, $ym->calendar);
    }

    // -------------------------------------------------------------------------
    // Mutation coverage: with() forwards era/eraYear
    // -------------------------------------------------------------------------

    public function testWithForwardsEraAndEraYear(): void
    {
        $ym = PlainYearMonth::parse('2024-06-01[u-ca=gregory]');
        $ym2 = $ym->with(era: 'ce', eraYear: 2020);

        static::assertSame(2020, $ym2->year);
    }

    public function testFromFieldsForwardsEraAndEraYear(): void
    {
        $ym = PlainYearMonth::fromFields(year: 2020, month: 6, calendar: Calendar::Gregory, era: 'ce', eraYear: 2020);

        static::assertSame(2020, $ym->year);
        static::assertSame('ce', $ym->era);
        static::assertSame(2020, $ym->eraYear);
    }
}
