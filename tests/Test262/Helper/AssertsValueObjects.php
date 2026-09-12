<?php

declare(strict_types=1);

namespace Calendrics\Tests\Test262\Helper;

use Calendrics\Tests\Test262\Assert;
use Calendrics\Tests\Test262\JsUndefined;
use PHPUnit\Framework\Assert as PHPUnitAssert;

/**
 * Value-object assertions ported from TC39's TemporalHelpers harness.
 *
 * Mirrors the upstream `TemporalHelpers.assert*` family: each method compares a
 * Temporal value object against expected field values (or against another value
 * object of the same type).
 *
 * Composed into {@see \Calendrics\Tests\Test262\TemporalHelpers}; the public
 * surface is `TemporalHelpers::assert*()`.
 */
trait AssertsValueObjects
{
    /**
     * Asserts that a Duration has the given field values.
     *
     * Argument order matches JS TemporalHelpers.assertDuration(d, y,m,w,d,h,min,s,ms,us,ns,msg).
     */
    public static function assertDuration(
        \Calendrics\Spec\Duration $duration,
        int|float $years,
        int|float $months,
        int|float $weeks,
        int|float $days,
        int|float $hours,
        int|float $minutes,
        int|float $seconds,
        int|float $milliseconds,
        int|float $microseconds,
        int|float $nanoseconds,
        string|int $description = '',
    ): void {
        $description = (string) $description;
        $prefix = $description !== '' ? "{$description}: " : '';
        Assert::sameValue($duration->years, $years, "{$prefix}years");
        Assert::sameValue($duration->months, $months, "{$prefix}months");
        Assert::sameValue($duration->weeks, $weeks, "{$prefix}weeks");
        Assert::sameValue($duration->days, $days, "{$prefix}days");
        Assert::sameValue($duration->hours, $hours, "{$prefix}hours");
        Assert::sameValue($duration->minutes, $minutes, "{$prefix}minutes");
        Assert::sameValue($duration->seconds, $seconds, "{$prefix}seconds");
        Assert::sameValue($duration->milliseconds, $milliseconds, "{$prefix}milliseconds");
        Assert::sameValue($duration->microseconds, $microseconds, "{$prefix}microseconds");
        Assert::sameValue($duration->nanoseconds, $nanoseconds, "{$prefix}nanoseconds");
    }

    /**
     * Asserts that two Durations have identical field values.
     *
     * Argument order matches JS TemporalHelpers.assertDurationsEqual(a, b, msg).
     */
    public static function assertDurationsEqual(
        \Calendrics\Spec\Duration $one,
        \Calendrics\Spec\Duration $two,
        string $description = '',
    ): void {
        self::assertDuration(
            $one,
            $two->years,
            $two->months,
            $two->weeks,
            $two->days,
            $two->hours,
            $two->minutes,
            $two->seconds,
            $two->milliseconds,
            $two->microseconds,
            $two->nanoseconds,
            $description,
        );
    }

    /**
     * Asserts that a PlainDate has the given year, month, monthCode, and day.
     *
     * Argument order matches JS TemporalHelpers.assertPlainDate(pd, year, month, monthCode, day, msg).
     *
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function assertPlainDate(
        \Calendrics\Spec\PlainDate $date,
        int $year,
        int|float $month,
        string $monthCode,
        int $day,
        string $description = '',
        mixed $era = null,
        mixed $eraYear = null,
    ): void {
        $prefix = $description !== '' ? "{$description}: " : '';
        PHPUnitAssert::assertSame($year, $date->year, "{$prefix}year");
        PHPUnitAssert::assertSame((int) $month, $date->month, "{$prefix}month");
        PHPUnitAssert::assertSame($monthCode, $date->monthCode, "{$prefix}monthCode");
        PHPUnitAssert::assertSame($day, $date->day, "{$prefix}day");
        // JsUndefined::isUndefined accepts both PHP null and the JsUndefined sentinel —
        // either is the test's way of saying "this calendar has no era; skip the check".
        if (!JsUndefined::isUndefined($era)) {
            PHPUnitAssert::assertSame($era, $date->era, "{$prefix}era");
        }
        if (!JsUndefined::isUndefined($eraYear)) {
            PHPUnitAssert::assertSame($eraYear, $date->eraYear, "{$prefix}eraYear");
        }
    }

    /**
     * Asserts that two Instants represent the same point in time.
     *
     * Argument order matches JS TemporalHelpers.assertInstantsEqual(a, b, msg).
     */
    public static function assertInstantsEqual(
        \Calendrics\Spec\Instant $one,
        \Calendrics\Spec\Instant $two,
        string $description = '',
    ): void {
        PHPUnitAssert::assertSame(
            $one->epochNanoseconds,
            $two->epochNanoseconds,
            $description !== '' ? $description : 'epochNanoseconds should be equal',
        );
    }

    /**
     * Asserts that a PlainTime has the given field values.
     *
     * Argument order matches JS TemporalHelpers.assertPlainTime(t, h, min, s, ms, us, ns, msg).
     *
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function assertPlainTime(
        \Calendrics\Spec\PlainTime $time,
        int $hour,
        int $minute,
        int $second,
        int $millisecond,
        int $microsecond,
        int $nanosecond,
        string|int $description = '',
    ): void {
        $description = (string) $description;
        $prefix = $description !== '' ? "{$description}: " : '';
        PHPUnitAssert::assertSame($hour, $time->hour, "{$prefix}hour");
        PHPUnitAssert::assertSame($minute, $time->minute, "{$prefix}minute");
        PHPUnitAssert::assertSame($second, $time->second, "{$prefix}second");
        PHPUnitAssert::assertSame($millisecond, $time->millisecond, "{$prefix}millisecond");
        PHPUnitAssert::assertSame($microsecond, $time->microsecond, "{$prefix}microsecond");
        PHPUnitAssert::assertSame($nanosecond, $time->nanosecond, "{$prefix}nanosecond");
    }

    /**
     * Asserts that two PlainTimes have identical field values.
     *
     * Argument order matches JS TemporalHelpers.assertPlainTimesEqual(one, two, msg).
     *
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function assertPlainTimesEqual(
        \Calendrics\Spec\PlainTime $one,
        \Calendrics\Spec\PlainTime $two,
        string $description = '',
    ): void {
        self::assertPlainTime(
            $one,
            $two->hour,
            $two->minute,
            $two->second,
            $two->millisecond,
            $two->microsecond,
            $two->nanosecond,
            $description,
        );
    }

    /**
     * Asserts that two PlainDates have identical field values.
     *
     * Argument order matches JS TemporalHelpers.assertPlainDatesEqual(one, two, msg).
     *
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function assertPlainDatesEqual(
        \Calendrics\Spec\PlainDate $one,
        \Calendrics\Spec\PlainDate $two,
        string $description = '',
    ): void {
        self::assertPlainDate($one, $two->year, $two->month, $two->monthCode, $two->day, $description);
    }

    /**
     * Asserts that a PlainDateTime has the given field values.
     *
     * Argument order matches JS TemporalHelpers.assertPlainDateTime(pdt, y, m, mc, d, h, min, s, ms, us, ns, msg).
     *
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function assertPlainDateTime(
        \Calendrics\Spec\PlainDateTime $dt,
        int $year,
        int|float $month,
        string $monthCode,
        int $day,
        int $hour,
        int $minute,
        int $second,
        int $millisecond,
        int $microsecond,
        int $nanosecond,
        string $description = '',
    ): void {
        $prefix = $description !== '' ? "{$description}: " : '';
        PHPUnitAssert::assertSame($year, $dt->year, "{$prefix}year");
        PHPUnitAssert::assertSame((int) $month, $dt->month, "{$prefix}month");
        PHPUnitAssert::assertSame($monthCode, $dt->monthCode, "{$prefix}monthCode");
        PHPUnitAssert::assertSame($day, $dt->day, "{$prefix}day");
        PHPUnitAssert::assertSame($hour, $dt->hour, "{$prefix}hour");
        PHPUnitAssert::assertSame($minute, $dt->minute, "{$prefix}minute");
        PHPUnitAssert::assertSame($second, $dt->second, "{$prefix}second");
        PHPUnitAssert::assertSame($millisecond, $dt->millisecond, "{$prefix}millisecond");
        PHPUnitAssert::assertSame($microsecond, $dt->microsecond, "{$prefix}microsecond");
        PHPUnitAssert::assertSame($nanosecond, $dt->nanosecond, "{$prefix}nanosecond");
    }

    /**
     * Asserts that two PlainDateTimes represent the same date and time.
     *
     * Argument order matches JS TemporalHelpers.assertPlainDateTimesEqual(one, two, msg).
     *
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function assertPlainDateTimesEqual(
        \Calendrics\Spec\PlainDateTime $one,
        \Calendrics\Spec\PlainDateTime $two,
        string $description = '',
    ): void {
        self::assertPlainDateTime(
            $two,
            $one->year,
            $one->month,
            $one->monthCode,
            $one->day,
            $one->hour,
            $one->minute,
            $one->second,
            $one->millisecond,
            $one->microsecond,
            $one->nanosecond,
            $description,
        );
    }

    /**
     * Asserts that a Duration has the given calendar field values.
     *
     * Argument order matches JS TemporalHelpers.assertDateDuration(d, y, m, w, days, msg).
     */
    public static function assertDateDuration(
        \Calendrics\Spec\Duration $duration,
        int|float $years,
        int|float $months,
        int|float $weeks,
        int|float $days,
        string $description = '',
    ): void {
        $prefix = $description !== '' ? "{$description}: " : '';
        PHPUnitAssert::assertSame($years, $duration->years, "{$prefix}years");
        PHPUnitAssert::assertSame($months, $duration->months, "{$prefix}months");
        PHPUnitAssert::assertSame($weeks, $duration->weeks, "{$prefix}weeks");
        PHPUnitAssert::assertSame($days, $duration->days, "{$prefix}days");
    }

    /**
     * Asserts that a PlainYearMonth has the given year, month, and monthCode.
     *
     * Argument order matches JS TemporalHelpers.assertPlainYearMonth(ym, year, month, monthCode, msg).
     *
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function assertPlainYearMonth(
        \Calendrics\Spec\PlainYearMonth $ym,
        int $year,
        int $month,
        string $monthCode,
        string $description = '',
    ): void {
        $prefix = $description !== '' ? "{$description}: " : '';
        PHPUnitAssert::assertSame($year, $ym->year, "{$prefix}year");
        PHPUnitAssert::assertSame($month, $ym->month, "{$prefix}month");
        PHPUnitAssert::assertSame($monthCode, $ym->monthCode, "{$prefix}monthCode");
    }

    /**
     * Asserts that two PlainYearMonths have identical field values.
     *
     * Argument order matches JS TemporalHelpers.assertPlainYearMonthsEqual(one, two, msg).
     *
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function assertPlainYearMonthsEqual(
        \Calendrics\Spec\PlainYearMonth $one,
        \Calendrics\Spec\PlainYearMonth $two,
        string $description = '',
    ): void {
        self::assertPlainYearMonth($one, $two->year, $two->month, $two->monthCode, $description);
    }

    /**
     * Asserts that a PlainMonthDay has the given monthCode and day.
     *
     * Argument order matches JS TemporalHelpers.assertPlainMonthDay(md, monthCode, day, msg[, refYear]).
     *
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function assertPlainMonthDay(
        \Calendrics\Spec\PlainMonthDay $md,
        string $monthCode,
        int $day,
        string $description = '',
        int $referenceISOYear = 1972,
    ): void {
        $prefix = $description !== '' ? "{$description}: " : '';
        PHPUnitAssert::assertSame($monthCode, $md->monthCode, "{$prefix}monthCode");
        PHPUnitAssert::assertSame($day, $md->day, "{$prefix}day");
        PHPUnitAssert::assertSame($referenceISOYear, $md->referenceISOYear, "{$prefix}referenceISOYear");
    }

    /**
     * Asserts that two ZonedDateTimes are equal (same epoch, timezone, and calendar).
     *
     * Argument order matches JS TemporalHelpers.assertZonedDateTimesEqual(actual, expected, msg).
     *
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function assertZonedDateTimesEqual(
        \Calendrics\Spec\ZonedDateTime $actual,
        \Calendrics\Spec\ZonedDateTime $expected,
        string $description = '',
    ): void {
        $prefix = $description !== '' ? "{$description}: " : '';
        PHPUnitAssert::assertTrue($actual->equals($expected), "{$prefix}equals method");
        PHPUnitAssert::assertSame($expected->timeZoneId, $actual->timeZoneId, "{$prefix}time zone same value:");
        PHPUnitAssert::assertSame($expected->calendarId, $actual->calendarId, "{$prefix}calendar same value:");
    }
}
